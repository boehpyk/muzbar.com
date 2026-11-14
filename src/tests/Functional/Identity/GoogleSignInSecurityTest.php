<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\ValueObject\Email;
use App\Infrastructure\Identity\Security\GoogleAuthenticator;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\DrivesGoogleOAuth;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The takeover rules around "Continue with Google" (AC-13, AC-14, AC-15, AC-17).
 *
 * EVERY NEGATIVE TEST SCRIPTS A PERFECTLY VALID GOOGLE ANSWER FIRST. A callback that should be
 * refused is only proven refused if, had it been accepted, it would have signed somebody in — so
 * Google is told to vouch for a real account and the test then asserts nobody was signed in and
 * that Google was never even asked (no token request). Without that, "neutral failure" could just
 * be the empty mock queue erroring out.
 */
final class GoogleSignInSecurityTest extends WebTestCase
{
    use ClearsRateLimiters;
    use DrivesGoogleOAuth;
    use RegistersAUserWithKnownCredentials;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    // ---- login CSRF / state / session binding (AC-13) -----------------------------------------

    public function testACallbackWithADifferentStateSignsNobodyInAndNeverReachesGoogle(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        $this->hitCallback($this->client, 'a-state-the-attacker-chose');

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests(), 'A bad state must be refused before the code is redeemed.');
    }

    public function testACallbackWithNoStateSignsNobodyInAndNeverReachesGoogle(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        $this->hitCallback($this->client, null);

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests());
    }

    public function testACallbackInADifferentBrowserSessionFromTheOneThatStartedTheFlowFails(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $flow = $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        // The attacker's browser: no cookie, so a brand-new session — but the victim's real state.
        $this->client->getCookieJar()->clear();
        $this->hitCallback($this->client, $flow['state']);

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests(), 'A session that never started the flow must not redeem a code.');
    }

    public function testACallbackWhoseStateBelongsToAnotherSessionFailsEvenWhenThisSessionStartedItsOwnFlow(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $victimFlow = $this->startGoogleFlow($this->client);

        // The attacker has a session of their own, with their own verifier and state.
        $this->client->getCookieJar()->clear();
        $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        $this->hitCallback($this->client, $victimFlow['state']);

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests());
    }

    public function testCancellingAtGoogleIsTheNeutralFailureAndNeverReachesGoogle(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $flow = $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        $this->client->request('GET', '/connect/google/check?'.http_build_query(['error' => 'access_denied', 'state' => $flow['state']]));

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests());
    }

    public function testACallbackWithNoCodeIsTheNeutralFailureAndNeverReachesGoogle(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $flow = $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));

        $this->hitCallback($this->client, $flow['state'], null);

        $this->assertRefusedWithNeutralFailure();
        self::assertSame([], $this->fakeGoogle()->requests());
    }

    public function testReplayingTheCallbackInTheSameSessionIsRefusedBecauseTheVerifierWasConsumed(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->provisionedViaGoogle('sub-victim')->create();
        $flow = $this->startGoogleFlow($this->client);
        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));
        $this->hitCallback($this->client, $flow['state']);
        self::assertResponseRedirects('/account');
        $requestsAfterFirst = \count($this->fakeGoogle()->requests());

        $this->fakeGoogle()->succeedWith($this->claims('sub-victim', 'victim@example.com'));
        $this->hitCallback($this->client, $flow['state']);

        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(GoogleAuthenticator::NEUTRAL_FAILURE, trim($crawler->filter('p.error')->text()));
        self::assertCount($requestsAfterFirst, $this->fakeGoogle()->requests(), 'The second callback must not redeem a code.');
    }

    // ---- passwordless accounts and form login (AC-14) -----------------------------------------

    /**
     * Compared AGAINST EACH OTHER, live, in one client — never to a remembered literal: the response
     * to a password for an account that has none must be indistinguishable from the response to a
     * wrong password for an account that does, or the login form becomes a free oracle for "which
     * of these addresses signed up with Google".
     */
    #[DataProvider('anyPassword')]
    public function testAPasswordlessAccountAnswersTheLoginFormExactlyLikeAWrongPassword(string $password): void
    {
        $this->registerUserWithKnownCredentials('has-password@example.com', 'the-real-password-1', verified: true);
        UserFactory::new(['email' => Email::fromString('no-password@example.com')])->provisionedViaGoogle('sub-no-password')->create();

        $withPassword = $this->loginAndObserve('has-password@example.com', $password);
        $passwordless = $this->loginAndObserve('no-password@example.com', $password);

        self::assertSame($withPassword['status'], $passwordless['status']);
        self::assertSame($withPassword['location'], $passwordless['location']);
        self::assertSame($withPassword['error'], $passwordless['error']);
        self::assertSame($withPassword['followStatus'], $passwordless['followStatus']);
        self::assertSame($withPassword['accountAfter'], $passwordless['accountAfter'], 'Neither attempt may sign anyone in.');

        // The concrete shape, so a regression that broke both identically still fails.
        self::assertSame(302, $passwordless['status']);
        self::assertSame('Invalid credentials.', $passwordless['error']);
        self::assertSame(302, $passwordless['accountAfter']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anyPassword(): iterable
    {
        yield 'a short wrong password' => ['wrong-1'];
        yield 'a long password' => [str_repeat('x', 200)];
        yield 'a hash-looking value' => ['$2y$04$'.str_repeat('a', 53)];
    }

    public function testAPasswordlessAccountIsNeverAServerError(): void
    {
        UserFactory::new(['email' => Email::fromString('no-password@example.com')])->provisionedViaGoogle('sub-no-password')->create();

        $observed = $this->loginAndObserve('no-password@example.com', 'whatever-password-1');

        self::assertLessThan(500, $observed['status']);
        self::assertLessThan(500, $observed['followStatus']);
    }

    // ---- adding a password is the forgot-password flow (AC-15) --------------------------------

    public function testAPasswordlessAccountCanSetAPasswordThroughForgotPasswordAndThenUseBothLogins(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('both@example.com')])->provisionedViaGoogle('sub-both')->create();

        // 1. forgot-password -> the mail -> the link -> the form -> a new password.
        $crawler = $this->client->request('GET', '/forgot-password');
        $csrf = $crawler->filter('input[name="forgot_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrf);
        $this->client->request('POST', '/forgot-password', ['forgot_password_form' => ['email' => 'both@example.com', '_token' => $csrf]]);
        self::assertResponseRedirects('/forgot-password/sent');

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertSame(1, preg_match('#https?://[^"\s<]+(/reset-password/[A-Za-z0-9_-]+)#', (string) $message->getHtmlBody(), $match));
        $resetPath = $match[1] ?? null;
        self::assertIsString($resetPath);

        $this->client->request('GET', $resetPath);
        self::assertResponseRedirects('/reset-password');
        $crawler = $this->client->request('GET', '/reset-password');
        $csrf = $crawler->filter('input[name="new_password_form[_token]"]')->attr('value');
        self::assertNotNull($csrf);
        $newPassword = 'a-password-the-google-user-chose-1';
        $this->client->request('POST', '/reset-password', [
            'new_password_form' => ['plainPassword' => ['first' => $newPassword, 'second' => $newPassword], '_token' => $csrf],
        ]);
        self::assertResponseRedirects('/login');

        // 2. The password now works at the form...
        $this->clearRateLimiterPool();
        $this->passwordLogin('both@example.com', $newPassword);
        self::assertResponseRedirects('/account');

        // 3. ...and Google still signs in to the SAME user, from a fresh browser.
        $this->client->getCookieJar()->clear();
        $this->signInWithGoogle($this->client, $this->claims('sub-both', 'both@example.com'));
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.account-email', 'both@example.com');

        self::assertSame(1, $this->userCount(), 'Neither path may have created a second User.');
        $row = $this->connection()->fetchAssociative('SELECT password_hash, oauth_identities::text AS ids FROM identity_user WHERE id = :id', ['id' => $user->id()->toString()]);
        self::assertIsArray($row);
        self::assertNotNull($row['password_hash']);
        self::assertIsString($row['ids']);
        self::assertJsonStringEqualsJsonString('[{"provider":"google","subject":"sub-both"}]', $row['ids']);
    }

    // ---- /register over a Google account (AC-17) ----------------------------------------------

    public function testRegisteringWithTheEmailOfAGoogleAccountBehavesLikeAnyExistingEmailAndSetsNoPassword(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('google-person@example.com')])->provisionedViaGoogle('sub-register')->create();
        $before = $this->connection()->fetchAssociative('SELECT * FROM identity_user WHERE id = :id', ['id' => $user->id()->toString()]);

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Register')->form();
        $form['registration_form[email]'] = 'Google-Person@Example.com';
        $form['registration_form[plainPassword][first]'] = 'an-attackers-chosen-password-1';
        $form['registration_form[plainPassword][second]'] = 'an-attackers-chosen-password-1';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'An account with this email already exists.');
        self::assertSame(1, $this->userCount());
        self::assertSame($before, $this->connection()->fetchAssociative('SELECT * FROM identity_user WHERE id = :id', ['id' => $user->id()->toString()]), 'No password set, nothing linked, nothing changed.');
        self::assertEmailCount(0);
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function assertRefusedWithNeutralFailure(): void
    {
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame(GoogleAuthenticator::NEUTRAL_FAILURE, trim($crawler->filter('p.error')->text()));
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login', null, 'Nobody may be signed in after a refused callback.');
    }

    private function passwordLogin(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['_username' => $email, '_password' => $password, '_csrf_token' => $csrf]);
    }

    /**
     * @return array{status: int, location: string|null, error: string, followStatus: int, accountAfter: int}
     */
    private function loginAndObserve(string $email, string $password): array
    {
        $this->client->getCookieJar()->clear();
        $this->passwordLogin($email, $password);
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $location = $response->headers->get('Location');

        $crawler = $this->client->followRedirect();
        $followStatus = $this->client->getResponse()->getStatusCode();
        $error = trim($crawler->filter('p.error')->count() > 0 ? $crawler->filter('p.error')->text() : '');

        $this->client->request('GET', '/account');

        return [
            'status' => $status,
            'location' => $location,
            'error' => $error,
            'followStatus' => $followStatus,
            'accountAfter' => $this->client->getResponse()->getStatusCode(),
        ];
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function userCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM identity_user');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
