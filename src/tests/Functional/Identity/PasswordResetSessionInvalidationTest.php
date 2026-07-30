<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for two cross-cutting properties of a successful reset — session invalidation
 * (AC-24) and the unverified-account path (AC-28) — against the real `muzbar_test` database (DAMA
 * rollback). T41 of `identity-password-reset`.
 *
 * `ClearsRateLimiters` protects `login_throttling` and `password_reset_submit`, both exercised here,
 * from a stale Redis counter left behind by an earlier test.
 *
 * A READ THAT MUST REFLECT WHAT A JUST-COMPLETED HTTP REQUEST WROTE USES `freshUsers()`, NEVER
 * `$this->users`. Doctrine's identity map is keyed by primary key regardless of how an entity first
 * got there — a prior `find*()` call or a Foundry factory's own `persist()` both count — so a
 * long-lived repository property can silently keep handing back the pre-mutation object instead of
 * re-querying. See `ResetPasswordSubmissionTest`'s class docblock for the concrete failure this
 * produced while this batch of tests was written.
 */
final class PasswordResetSessionInvalidationTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;

    private KernelBrowser $client;
    private UserRepository $users;
    private ResetTokenGenerator $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $this->tokens = $tokens;
    }

    /**
     * AC-24 — the property the user story is actually about: a session authenticated as an account
     * *before* its password was reset is deauthenticated on its very next request. Log in, confirm
     * `/account` is 200, perform the *whole* reset in the same client (so the same session cookie is
     * still in play), then confirm `/account` now bounces to `/login`.
     *
     * THIS BEHAVIOUR IS INHERITED, NOT BUILT. `ResetPasswordController`/`ResetPasswordWithTokenHandler`
     * contain no line that touches a session or a token. The mechanism is `DomainUserProvider::refreshUser()`
     * rebuilding `SecurityUser` from the database on every request, combined with Symfony's own
     * `AbstractToken::hasUserChanged()`, which compares `getPassword()` between the session's stored
     * token and the freshly refreshed user — a changed hash is a changed user, and a changed user is
     * deauthenticated. That is exactly why this test exists asserted end-to-end rather than reasoned
     * about from the framework's source: it is what stops someone silently breaking the guarantee by
     * changing `SecurityUser::getPassword()` to return a constant, or by dropping
     * `PasswordAuthenticatedUserInterface` from that class. Nothing in this test file would need to
     * change if that ever happened — it would simply start failing, which is the point.
     */
    public function testResettingThePasswordDeauthenticatesAnAlreadyLoggedInSession(): void
    {
        $this->registerUserWithKnownCredentials('session-invalidation@example.com', 'the-original-password-1', verified: true);

        $this->login('session-invalidation@example.com', 'the-original-password-1');
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'session-invalidation@example.com');

        // The whole reset, in the SAME client — the session cookie from the login above is still
        // attached to every request the client makes from here on.
        $user = $this->users->findByEmail(Email::fromString('session-invalidation@example.com'));
        self::assertNotNull($user);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        $crawler = $this->client->request('GET', '/reset-password');
        $csrfToken = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-completely-different-password', 'second' => 'a-completely-different-password'],
                '_token' => $csrfToken,
            ],
        ]);
        self::assertResponseRedirects('/login');

        // The property under test: the session that was authenticated before the reset is now dead.
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    /**
     * AC-28: an account that never touched the verification flow at all can request and complete a
     * password reset, and doing so also verifies its email — so it becomes able to log in for the
     * first time, with the *new* password, having never clicked a verification link.
     *
     * Asserted through an actual `/login` POST rather than by reading `email_verified_at` off the
     * row, per the AC's own wording — and the login attempt *before* the reset is included
     * deliberately: it proves the account really was locked out (`VerifiedAccountUserChecker`
     * enforces `User::isUsable()`), which is what makes the "and now it can" half of this test mean
     * something. Without that first half, a bug that left the checker permissive for everyone would
     * make this test pass for the wrong reason.
     */
    public function testAnUnverifiedAccountCanResetItsPasswordAndThenLogInWithoutEverVerifying(): void
    {
        // `verified: false` (the trait's default) — this account never touches `/verify-email` at
        // any point in this test.
        $user = $this->registerUserWithKnownCredentials('unverified-reset@example.com', 'the-original-password-1');
        self::assertFalse($user->isEmailVerified());

        // BEFORE the reset: the account is genuinely locked out, even with the correct password.
        $this->login('unverified-reset@example.com', 'the-original-password-1');
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(
            'Please verify your email address before signing in.',
            trim($crawler->filter('p.error')->text()),
        );

        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        $resetCrawler = $this->client->request('GET', '/reset-password');
        $csrfToken = $resetCrawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-brand-new-strong-password', 'second' => 'a-brand-new-strong-password'],
                '_token' => $csrfToken,
            ],
        ]);
        self::assertResponseRedirects('/login');

        $foundUser = $this->freshUsers()->findById($user->id());
        self::assertNotNull($foundUser);
        self::assertTrue($foundUser->isEmailVerified(), 'The reset must have verified the email as a side effect.');

        // AFTER the reset: signs in successfully with the NEW password, never having visited
        // `/verify-email` at any point.
        $this->login('unverified-reset@example.com', 'a-brand-new-strong-password');
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'unverified-reset@example.com');
    }

    /**
     * A brand-new `UserRepository` instance, so its next query is a genuine round trip rather than
     * an identity-map hit on an object cached before the most recent HTTP request.
     */
    private function freshUsers(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private function login(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/login', [
            '_username' => $username,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
    }
}
