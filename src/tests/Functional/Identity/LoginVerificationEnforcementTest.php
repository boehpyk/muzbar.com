<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the login-enforcement half of this slice — ADR-0005's invariant, finally
 * acted on by `VerifiedAccountUserChecker` (AC-20 … AC-23), inverting slice 1's AC-24.
 *
 * `ClearsRateLimiters` is required here too: the throttle test below deliberately exhausts
 * `login_throttling`'s budget, and every other test in this file authenticates, so a stale counter
 * from an earlier test would make an unrelated login attempt fail with the throttling message
 * instead of the one it is actually testing for.
 */
final class LoginVerificationEnforcementTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
    }

    /**
     * AC-20, AC-21: the whole point of this slice's enforcement, asserted against the *same*
     * account in one test rather than two. `checkPostAuth()` runs only once a password has been
     * accepted, so it can never become a free enumeration oracle — the way to prove that is to show
     * that a *wrong* password on this same unverified account produces the ordinary message, not the
     * verification one. Splitting this into two tests would let a regression that moved the check to
     * `checkPreAuth()` still pass each test in isolation; only asserting both against one account in
     * one test catches it.
     */
    public function testAnUnverifiedAccountBlocksCorrectCredentialsButAWrongPasswordStillShowsTheOrdinaryMessage(): void
    {
        $this->registerUserWithKnownCredentials('unverified-login@example.com', 'the-correct-password-1', verified: false);

        // AC-20: correct credentials, refused, no session.
        $this->login('unverified-login@example.com', 'the-correct-password-1');
        self::assertResponseRedirects('/login');
        $crawler = $this->client->followRedirect();
        self::assertSame('Please verify your email address before signing in.', trim($crawler->filter('p.error')->text()));

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');

        // AC-21: same account, wrong password — the ordinary message, never the verification one.
        $this->login('unverified-login@example.com', 'totally-the-wrong-password');
        self::assertResponseRedirects('/login');
        $wrongPasswordCrawler = $this->client->followRedirect();
        self::assertSame('Invalid credentials.', trim($wrongPasswordCrawler->filter('p.error')->text()));
    }

    /**
     * AC-22: a verified user's login is unaffected — 302 to `/account`, session established. The
     * inverse of the pairing above: the checker must let a legitimate login through exactly as
     * slice 1 designed it to.
     */
    public function testAVerifiedUsersLoginIsUnaffectedAndEstablishesASession(): void
    {
        $this->registerUserWithKnownCredentials('verified-login@example.com', 'a-correct-password-1', verified: true);

        $this->login('verified-login@example.com', 'a-correct-password-1');

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-email', 'verified-login@example.com');
    }

    /**
     * AC-23: the failed unverified login counts against `login_throttling` exactly like any other
     * failure. Five wrong-password attempts exhaust the budget; the 6th attempt — even with the
     * *correct* password, which would otherwise be blocked by `VerifiedAccountUserChecker` with the
     * verification message — is refused by the throttle first, because the throttle limiter runs
     * before any credential or post-auth check at all.
     */
    public function testTheThrottleStillWinsOverTheVerificationMessageAtTheSixthAttempt(): void
    {
        $this->registerUserWithKnownCredentials('throttled-unverified@example.com', 'the-correct-password-1', verified: false);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('throttled-unverified@example.com', 'a-wrong-password-'.$attempt);
            self::assertResponseRedirects('/login');
            $error = trim($this->client->followRedirect()->filter('p.error')->text());
            self::assertSame('Invalid credentials.', $error, \sprintf('Attempt %d should not yet be throttled.', $attempt));
        }

        $this->login('throttled-unverified@example.com', 'the-correct-password-1');

        self::assertResponseRedirects('/login');
        $error = trim($this->client->followRedirect()->filter('p.error')->text());
        self::assertSame('Too many failed login attempts, please try again in 15 minutes.', $error);
        self::assertNotSame('Please verify your email address before signing in.', $error);
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
