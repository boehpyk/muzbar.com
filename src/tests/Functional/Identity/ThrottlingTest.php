<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * AC-14: five failed login attempts for the same (username, IP) are allowed to fail normally; the
 * sixth is rejected before any password verification, with the throttling message.
 *
 * See `ClearsRateLimiters` for why the pool is cleared in `setUp()` — without it, this is the
 * single most likely test in the whole slice to fail non-deterministically, because the counter
 * this test depends on lives in real Redis and survives both other tests and repeated suite runs.
 */
final class ThrottlingTest extends WebTestCase
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
     * The exact wording is the `%minutes%` variant Symfony's `security` translation domain
     * renders whenever `interval` is configured — not "...try again later." (amended in the
     * feature spec 2026-07-26 once this was observed under implementation).
     */
    public function testTheSixthFailedAttemptIsThrottledWithADistinctMessage(): void
    {
        $this->registerUserWithKnownCredentials('throttle-check@example.com', 'the-correct-password-1');

        $errors = [];
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->login('throttle-check@example.com', 'a-wrong-password-'.$attempt);
            self::assertResponseRedirects('/login');
            $errors[$attempt] = trim($this->client->followRedirect()->filter('p.error')->text());
        }

        // Attempts 1-5 fail normally, on the merits of the (wrong) password.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertSame('Invalid credentials.', $errors[$attempt], \sprintf('Attempt %d should not yet be throttled.', $attempt));
        }

        // The 6th is rejected by the throttle, with a distinct message.
        self::assertSame(
            'Too many failed login attempts, please try again in 15 minutes.',
            $errors[6],
        );
        self::assertNotSame($errors[5], $errors[6]);
    }

    /**
     * The throttle also protects a correct password once the budget for this (username, IP) is
     * exhausted: the 6th attempt is rejected before password verification even runs, so a right
     * password submitted as the 6th attempt must still be refused.
     */
    public function testTheSixthAttemptIsThrottledEvenWithTheCorrectPassword(): void
    {
        $this->registerUserWithKnownCredentials('throttle-correct-password@example.com', 'the-correct-password-1');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('throttle-correct-password@example.com', 'a-wrong-password-'.$attempt);
        }

        $this->login('throttle-correct-password@example.com', 'the-correct-password-1');

        self::assertResponseRedirects('/login');
        $error = trim($this->client->followRedirect()->filter('p.error')->text());
        self::assertSame('Too many failed login attempts, please try again in 15 minutes.', $error);
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
