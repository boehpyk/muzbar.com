<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\EmailVerificationRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for `GET|POST /verify-email/resend` against the real `muzbar_test` database
 * (DAMA rollback).
 *
 * `ClearsRateLimiters` is used because both the domain rate limit's 6th-attempt test and the IP
 * limiter's own 429 test would otherwise interact with counters left behind by any earlier test in
 * the suite that also touched this endpoint (or `/login`) — the same Redis-survives-DAMA gotcha
 * `LoginLogoutTest` and `ThrottlingTest` already guard against.
 */
final class EmailVerificationResendTest extends WebTestCase
{
    use ClearsRateLimiters;
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    /**
     * AC-14: the form renders a single `email` field and a CSRF token.
     */
    public function testGetResendRendersTheFormWithAnEmailFieldAndACsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/verify-email/resend');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="resend_verification_form[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="resend_verification_form[_token]"]'));
    }

    /**
     * AC-15 (the anti-enumeration property): four inputs with four different underlying account
     * states — an unverified existing account, an address nobody holds, an already-verified
     * account, and an account already over its domain rate limit — produce a byte-identical
     * response: same status, same `Location`, same rendered body after following the redirect. Only
     * the first of the four results in an email.
     */
    public function testFourDifferentAccountStatesProduceAByteIdenticalResponse(): void
    {
        $unverified = UserFactory::createOne(['email' => Email::fromString('resend-unverified@example.com')]);
        UserFactory::new(['email' => Email::fromString('resend-already-verified@example.com')])->verified()->create();
        $overLimit = UserFactory::createOne(['email' => Email::fromString('resend-over-limit@example.com')]);
        EmailVerificationRequestFactory::createMany(EmailVerificationRequest::MAX_ISSUES_PER_HOUR, [
            'userId' => $overLimit->id(),
            'issuedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);

        $cases = [
            'unverified-existing' => 'resend-unverified@example.com',
            'unknown-address' => 'resend-nobody-here@example.com',
            'already-verified' => 'resend-already-verified@example.com',
            'over-domain-limit' => 'resend-over-limit@example.com',
        ];

        $results = [];
        foreach ($cases as $label => $email) {
            $crawler = $this->client->request('GET', '/verify-email/resend');
            $token = $crawler->filter('input[name="resend_verification_form[_token]"]')->attr('value');
            self::assertNotNull($token);

            $this->client->request('POST', '/verify-email/resend', [
                'resend_verification_form' => ['email' => $email, '_token' => $token],
            ]);

            $response = $this->client->getResponse();

            // GOTCHA: `KernelBrowser` reboots the kernel (and therefore rebuilds the container,
            // including a fresh `mailer.message_logger_listener` with no history) before *every*
            // request unless `disableReboot()` is called — including the request `followRedirect()`
            // is about to make below. So the only email-count assertion for THIS case's POST that
            // can possibly be correct is one made *before* the redirect is followed; deferred to
            // after the loop (as the equivalent Registration test's mail assertion is), it would only
            // ever see whichever case's mail happened to survive the last reboot.
            self::assertEmailCount('unverified-existing' === $label ? 1 : 0, message: "Email count wrong for case '{$label}'.");

            $body = $this->client->followRedirect()->filter('body')->html();

            $results[$label] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'body' => $body,
            ];
        }

        $reference = $results['unverified-existing'];
        foreach ($results as $label => $result) {
            self::assertSame($reference['status'], $result['status'], "Status differed for case '{$label}'.");
            self::assertSame($reference['location'], $result['location'], "Location differed for case '{$label}'.");
            self::assertSame($reference['body'], $result['body'], "Body differed for case '{$label}'.");
        }
    }

    /**
     * AC-16: resending for the unverified-existing case creates a *new* row with a *distinct*
     * `token_hash` and sends one new email — the previously issued link is not invalidated (still
     * present, still unredeemed).
     */
    public function testResendingForAnUnverifiedAccountIssuesANewDistinctRequestWithoutInvalidatingThePrevious(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('resend-new-token@example.com')]);
        $previous = EmailVerificationRequestFactory::createOne(['userId' => $user->id()]);
        $previousHash = $previous->tokenHash()->toString();

        $crawler = $this->client->request('GET', '/verify-email/resend');
        $token = $crawler->filter('input[name="resend_verification_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', '/verify-email/resend', [
            'resend_verification_form' => ['email' => 'resend-new-token@example.com', '_token' => $token],
        ]);

        self::assertResponseRedirects('/verify-email/sent');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT token_hash, redeemed_at FROM identity_email_verification_request WHERE user_id = ?',
            [$user->id()->toString()],
        );
        self::assertCount(2, $rows);

        $hashes = array_map(static function (array $row): string {
            $hash = $row['token_hash'];
            if (!\is_string($hash)) {
                self::fail('Expected token_hash to be a string column value.');
            }

            return $hash;
        }, $rows);
        self::assertContains($previousHash, $hashes, 'The previous request row must still exist.');
        self::assertCount(2, array_unique($hashes), 'The new row must carry a distinct token_hash.');

        foreach ($rows as $row) {
            self::assertNull($row['redeemed_at'], 'Neither row should have been redeemed by a resend.');
        }

        self::assertEmailCount(1);
    }

    /**
     * AC-18: the Redis-backed IP limiter refuses the 6th POST within the rolling hour with a 429,
     * before the controller body (and therefore the handler and the mailer) ever runs. Different
     * addresses are used across the six attempts so the *domain* rate limit (5/user/hour) cannot
     * also be the reason for a refusal — this test isolates the IP limiter specifically.
     */
    public function testTheSixthPostFromOneIpIsRefusedWith429(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $crawler = $this->client->request('GET', '/verify-email/resend');
            $token = $crawler->filter('input[name="resend_verification_form[_token]"]')->attr('value');
            self::assertNotNull($token);

            $this->client->request('POST', '/verify-email/resend', [
                'resend_verification_form' => ['email' => \sprintf('ip-limit-%d@example.com', $attempt), '_token' => $token],
            ]);

            self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode(), \sprintf('Attempt %d should not yet be IP-throttled.', $attempt));
            self::assertSame('/verify-email/sent', $this->client->getResponse()->headers->get('Location'), \sprintf('Attempt %d should not yet be IP-throttled.', $attempt));
        }

        $crawler = $this->client->request('GET', '/verify-email/resend');
        $token = $crawler->filter('input[name="resend_verification_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/verify-email/resend', [
            'resend_verification_form' => ['email' => 'ip-limit-6@example.com', '_token' => $token],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * A missing/tampered CSRF token yields a form error (422) and no mutation — the same convention
     * `RegistrationControllerTest` establishes for `/register`.
     */
    public function testATamperedCsrfTokenIsRejected(): void
    {
        UserFactory::createOne(['email' => Email::fromString('resend-csrf-check@example.com')]);

        $this->client->request('POST', '/verify-email/resend', [
            'resend_verification_form' => ['email' => 'resend-csrf-check@example.com', '_token' => 'this-is-not-a-valid-token'],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertEmailCount(0);
    }

    /**
     * AC-19: nothing on the resend flow's success path reveals a token, a request id, or a
     * timestamp of a previous request — the neutral flash is the entire body of the answer.
     */
    public function testTheNeutralResponseRevealsNoTokenRequestIdOrTimestamp(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('resend-no-leak@example.com')]);
        $existing = EmailVerificationRequestFactory::createOne(['userId' => $user->id()]);

        $crawler = $this->client->request('GET', '/verify-email/resend');
        $token = $crawler->filter('input[name="resend_verification_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/verify-email/resend', [
            'resend_verification_form' => ['email' => 'resend-no-leak@example.com', '_token' => $token],
        ]);

        $body = (string) $this->client->followRedirect()->outerHtml();

        self::assertStringNotContainsString($existing->tokenHash()->toString(), $body);
        self::assertStringNotContainsString($existing->id()->toString(), $body);
        self::assertStringNotContainsString($existing->issuedAt()->format(\DateTimeInterface::ATOM), $body);
    }
}
