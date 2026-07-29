<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for `POST /reset-password` (the reset itself) against the real `muzbar_test`
 * database (DAMA rollback). T40 of `identity-password-reset` (AC-18, AC-20, AC-21, AC-23, AC-25).
 *
 * `ClearsRateLimiters` protects `password_reset_submit` (this route's own IP limiter) and
 * `login_throttling` (exercised by the AC-21 test's two `/login` attempts) from a stale Redis
 * counter left behind by an earlier test — DAMA rolls back Postgres, never Redis.
 *
 * ANY READ THAT MUST REFLECT WHAT AN HTTP REQUEST JUST COMMITTED USES A FRESHLY FETCHED REPOSITORY
 * (`freshRequests()`/`freshUsers()`), NEVER `$this->requests`/`$this->users`. Doctrine's identity map
 * is keyed by primary key regardless of how an entity first entered it — a prior `find*()` call or a
 * Foundry factory's `persist()` both count — so once one of those long-lived properties has seen a
 * given row, it keeps returning that same in-memory object on every later call rather than
 * re-querying, which silently reports the state the row had *before* the most recent request rather
 * than what that request actually wrote. Verified the hard way while writing this file: an earlier
 * draft asserted `isRedeemed()` off `$this->requests` a second time and read back `false` from a row
 * a raw `SELECT` on the same connection showed as genuinely redeemed.
 */
final class ResetPasswordSubmissionTest extends WebTestCase
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
     * AC-20 (the password changes, the request is redeemed) and AC-21 (the credential actually
     * changed — asserted through the real firewall, both halves in one test, against the same
     * account, because "the new password works" alone would stay green even if the old one still
     * did too) and AC-25 (no auto-login: the success redirect goes to `/login`, and the very next
     * request to `/account` still bounces there).
     */
    public function testResettingChangesTheCredentialRedeemsTheRequestAndDoesNotAutoLogIn(): void
    {
        $this->registerUserWithKnownCredentials('reset-submit-happy@example.com', 'the-old-correct-password-1');

        $hash = $this->completeAReset('reset-submit-happy@example.com', 'a-brand-new-strong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'sign in with it');

        $foundRequest = $this->freshRequests()->findByTokenHash($hash);
        self::assertNotNull($foundRequest);
        self::assertTrue($foundRequest->isRedeemed());

        // AC-25: the browser that performed the reset is not logged in.
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');

        // AC-21, both halves, same account, same test.
        $this->login('reset-submit-happy@example.com', 'the-old-correct-password-1');
        self::assertResponseRedirects('/login');
        $oldPasswordError = trim($this->client->followRedirect()->filter('p.error')->text());
        self::assertSame('Invalid credentials.', $oldPasswordError);

        $this->login('reset-submit-happy@example.com', 'a-brand-new-strong-password');
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'reset-submit-happy@example.com');
    }

    /**
     * AC-23: a submission that fails `PlainPassword`'s policy is refused with 422, and — the
     * assertion that actually matters — the same session-stashed token is still able to complete a
     * successful reset on the very next attempt, in the same test. A recovery flow that spent the
     * user's one link on their own typo would be a support ticket by construction.
     */
    public function testAWeakPasswordGives422AndTheSameStashedTokenSurvivesToASubsequentSuccess(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-submit-weak@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/reset-password');

        $crawler = $this->client->request('GET', '/reset-password');
        $csrfToken = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $rejectedCrawler = $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'too-short', 'second' => 'too-short'],
                '_token' => $csrfToken,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $foundRequest = $this->freshRequests()->findByTokenHash($hash);
        self::assertNotNull($foundRequest);
        self::assertNull($foundRequest->redeemedAt(), 'A weak password must not burn the token.');

        // The same stash, presented again, still works — proof the token survived.
        $freshCsrfToken = $rejectedCrawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($freshCsrfToken);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'a-perfectly-strong-new-password', 'second' => 'a-perfectly-strong-new-password'],
                '_token' => $freshCsrfToken,
            ],
        ]);

        self::assertResponseRedirects('/login');
        $redeemedRequest = $this->freshRequests()->findByTokenHash($hash);
        self::assertNotNull($redeemedRequest);
        self::assertTrue($redeemedRequest->isRedeemed());
    }

    /**
     * AC-18: presenting the same link a second time, after a successful reset, produces the
     * invalid-link response rather than a friendly "already done" branch — the sharpest inversion of
     * `EmailVerificationRequest`'s replay handling, because setting a password is destructive and
     * repeatable.
     */
    public function testAReplayOfTheSameLinkAfterASuccessfulResetIsRefused(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-submit-replay@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        $this->client->request('GET', '/reset-password/'.$token->reveal());
        $crawler = $this->client->request('GET', '/reset-password');
        $csrfToken = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $this->client->request('POST', '/reset-password', [
            'new_password_form' => [
                'plainPassword' => ['first' => 'the-first-successful-password', 'second' => 'the-first-successful-password'],
                '_token' => $csrfToken,
            ],
        ]);
        self::assertResponseRedirects('/login');

        $hashAfterFirstReset = $this->freshRequests()->findByTokenHash($hash)?->redeemedAt();
        self::assertNotNull($hashAfterFirstReset);

        // The SAME link, presented again — the mail is still sitting in the inbox.
        $this->client->request('GET', '/reset-password/'.$token->reveal());
        self::assertResponseRedirects('/forgot-password');
        $replayCrawler = $this->client->followRedirect();
        self::assertSame(
            'That link is no longer valid. Enter your address and we will send a new one.',
            trim($replayCrawler->filter('.flash-error')->text()),
        );

        // Not changed a second time — read fresh again, so a real second redemption (which would
        // advance the timestamp) could actually make this assertion fail.
        self::assertEquals($hashAfterFirstReset, $this->freshRequests()->findByTokenHash($hash)?->redeemedAt());
    }

    /**
     * Drives the GET-then-POST reset flow to completion for a given email and new password, all
     * inside `$this->client`, and returns the request's `tokenHash` for follow-up assertions.
     */
    private function completeAReset(string $email, string $newPassword): HashedResetToken
    {
        $user = $this->users->findByEmail(Email::fromString($email));
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
                'plainPassword' => ['first' => $newPassword, 'second' => $newPassword],
                '_token' => $csrfToken,
            ],
        ]);

        return $hash;
    }

    /**
     * See the class docblock: a brand-new repository instance, so its next query is a genuine round
     * trip rather than an identity-map hit on an object cached before the most recent HTTP request.
     */
    private function freshRequests(): PasswordResetRequestRepository
    {
        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);

        return $requests;
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
