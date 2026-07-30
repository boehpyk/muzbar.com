<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\ValueObject\Email;
use App\Infrastructure\Identity\Mail\TwigPasswordResetMailer;
use App\Infrastructure\Identity\Presentation\LifetimePhrase;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\RecordingLogger;
use App\Tests\Fixture\ThrowingPasswordResetMailer;
use App\Tests\Support\ClearsRateLimiters;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for `GET|POST /forgot-password` against the real `muzbar_test` database (DAMA
 * rollback). T38 of `identity-password-reset` (AC-1, AC-3, AC-5, AC-8, AC-9).
 *
 * `ClearsRateLimiters` is required because two of the four limiters that now share
 * `cache.rate_limiter` are exercised directly by this file (`password_reset_request`, the IP limiter
 * on this exact route) and the domain cap (`PasswordResetRequest::MAX_ISSUES_PER_HOUR`) is a
 * Postgres row that DAMA *does* roll back — but the IP limiter's Redis counter does not roll back
 * with it, so a stale counter from an earlier test would 429 an unrelated attempt.
 */
final class ForgotPasswordTest extends WebTestCase
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
     * AC-1: the form renders a single `email` field and a CSRF token.
     */
    public function testGetFormRendersAnEmailFieldAndACsrfToken(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="forgot_password_form[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="forgot_password_form[_token]"]'));
    }

    /**
     * AC-5, AC-6 (the anti-enumeration property, and the one to get right): four *well-formed*
     * submissions with four different underlying realities — an account that exists, an address
     * nobody holds, an account already at its per-user hourly cap, and an address that passes the
     * form's `Assert\Email(mode: strict)` but that `Email::fromString()`'s `FILTER_VALIDATE_EMAIL`
     * rejects (the strict-validator gap; verified empirically against this stack that
     * `üser@example.com` lands exactly in that gap) — produce a response indistinguishable from one
     * another: same status, same `Location`, same flash text, captured in **one run** with **one
     * client** and compared to *each other*, never to a remembered literal that could drift apart
     * while every copy stayed green (CLAUDE.md's own warning, and the mistake slice 2 made once).
     *
     * Only the known-account case writes a row or sends a mail — asserted via the table's total row
     * count around each POST, which is a stronger claim than counting rows for a fabricated "unknown"
     * user id that could never have one anyway.
     */
    public function testFourWellFormedInputsWithDifferentRealitiesProduceByteIdenticalResponses(): void
    {
        $knownUser = UserFactory::createOne(['email' => Email::fromString('forgot-known@example.com')]);

        $overCapUser = UserFactory::createOne(['email' => Email::fromString('forgot-over-cap@example.com')]);
        PasswordResetRequestFactory::createMany(PasswordResetRequest::MAX_ISSUES_PER_HOUR, [
            'userId' => $overCapUser->id(),
            'issuedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);

        $cases = [
            'known-account' => 'forgot-known@example.com',
            'unknown-address' => 'forgot-nobody-here@example.com',
            'over-domain-cap' => 'forgot-over-cap@example.com',
            // The strict-validator gap: valid per RFC (egulias/email-validator's RFCValidation, which
            // `Assert\Email(mode: strict)` uses) but rejected by `FILTER_VALIDATE_EMAIL`.
            'validator-gap' => 'üser@example.com',
        ];

        $results = [];
        foreach ($cases as $label => $email) {
            $rowsBefore = $this->countPasswordResetRequestRows();

            $crawler = $this->client->request('GET', '/forgot-password');
            $token = $crawler->filter('input[name="forgot_password_form[_token]"]')->attr('value');
            self::assertNotNull($token);

            $this->client->request('POST', '/forgot-password', [
                'forgot_password_form' => ['email' => $email, '_token' => $token],
            ]);
            $response = $this->client->getResponse();

            // Before `followRedirect()` reboots the kernel and discards the mailer message logger's
            // history (the same gotcha `EmailVerificationResendTest` documents at length).
            self::assertEmailCount('known-account' === $label ? 1 : 0, message: "Email count wrong for case '{$label}'.");

            $rowsAfter = $this->countPasswordResetRequestRows();
            self::assertSame(
                'known-account' === $label ? $rowsBefore + 1 : $rowsBefore,
                $rowsAfter,
                "Row count changed unexpectedly for case '{$label}'.",
            );

            $flash = trim($this->client->followRedirect()->filter('.flash-success')->text());

            $results[$label] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'flash' => $flash,
            ];
        }

        // Pairwise, against each other — not against a hardcoded literal repeated four times.
        $reference = $results['known-account'];
        foreach ($results as $label => $result) {
            self::assertSame($reference['status'], $result['status'], "Status differed for case '{$label}'.");
            self::assertSame($reference['location'], $result['location'], "Location differed for case '{$label}'.");
            self::assertSame($reference['flash'], $result['flash'], "Flash differed for case '{$label}'.");
        }

        // And the concrete shape of the shared outcome, so a regression that broke all four
        // identically (leaving every comparison above green) still fails this test.
        self::assertSame(Response::HTTP_FOUND, $reference['status']);
        self::assertSame('/forgot-password/sent', $reference['location']);
        self::assertSame(
            'If that address belongs to an account, we have sent it a link for setting a new password. Please check your inbox.',
            $reference['flash'],
        );
    }

    /**
     * AC-3: the happy path sends exactly one mail carrying a working absolute link. "Working" is
     * asserted by actually visiting the extracted URL and confirming it behaves like a live reset
     * link (a 302 to the token-less `/reset-password` route) rather than the invalid-link response —
     * not merely that the string looks like a URL.
     */
    public function testASuccessfulRequestSendsOneMailWithAWorkingAbsoluteLink(): void
    {
        UserFactory::createOne(['email' => Email::fromString('forgot-working-link@example.com')]);

        $crawler = $this->client->request('GET', '/forgot-password');
        $token = $crawler->filter('input[name="forgot_password_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-working-link@example.com', '_token' => $token],
        ]);
        self::assertResponseRedirects('/forgot-password/sent');

        // Captured immediately, before any further request reboots the kernel and clears the
        // message logger's history.
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);

        $html = (string) $message->getHtmlBody();
        $resetUrl = $this->extractResetUrl($html);

        $path = parse_url($resetUrl, \PHP_URL_PATH);
        self::assertIsString($path);

        $this->client->request('GET', $path);

        // A working link resolves to the token-less reset form, not to the invalid-link response.
        self::assertResponseRedirects('/reset-password');
    }

    /**
     * The defect `LifetimePhrase` exists to fix, caught at the actual surface: the reset mail's text
     * body must state the lifetime derived from `PasswordResetRequest::LIFETIME_SECONDS`, never a
     * literal typed in the template — follows `RegistrationControllerTest`'s precedent for slice 2's
     * verification mail. `LifetimePhraseTest` already pins the helper's own arithmetic in isolation;
     * this test is the other half — that `TwigPasswordResetMailer` is actually wired to call it with
     * the real constant, which a hardcoded "1 hour" in the template would satisfy just as silently as
     * it did before this class existed.
     */
    public function testTheResetMailStatesTheLifetimeDerivedFromTheDomainConstant(): void
    {
        UserFactory::createOne(['email' => Email::fromString('forgot-lifetime-mail@example.com')]);

        $token = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-lifetime-mail@example.com', '_token' => $token],
        ]);
        self::assertResponseRedirects('/forgot-password/sent');

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);

        $textBody = $message->getTextBody();
        self::assertIsString($textBody);

        $lifetime = LifetimePhrase::forSeconds(PasswordResetRequest::LIFETIME_SECONDS);
        self::assertStringContainsString(\sprintf('expires in %s, at', $lifetime), $textBody);
    }

    /**
     * The same defect, caught at the other surface `LifetimePhrase` was built to unify: the
     * "check your inbox" page (AC-36's byte-identical-for-everyone page) states the same lifetime,
     * reachable by a direct GET from anyone since the URL is anonymous — no form submission needed to
     * observe it.
     */
    public function testTheSentPageStatesTheLifetimeDerivedFromTheDomainConstant(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password/sent');

        self::assertResponseIsSuccessful();
        $lifetime = LifetimePhrase::forSeconds(PasswordResetRequest::LIFETIME_SECONDS);
        self::assertStringContainsString(
            \sprintf('expires after %s', $lifetime),
            $crawler->filter('body')->text(),
        );
    }

    /**
     * AC-9 (the inversion of ADR-0009 decision 5): issuing a second request for the same account
     * invalidates the first, and the first mail's link — which a real user may still be holding open
     * in another tab — now produces the invalid-link response. Everything happens inside one client,
     * because the session that eventually matters here is what makes this a meaningful end-to-end
     * test rather than two unrelated requests.
     */
    public function testANewRequestInvalidatesTheOldLinkWhichThenFails(): void
    {
        UserFactory::createOne(['email' => Email::fromString('forgot-reissue@example.com')]);

        $firstToken = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-reissue@example.com', '_token' => $firstToken],
        ]);
        self::assertResponseRedirects('/forgot-password/sent');

        // Captured now — the *next* request (even just fetching a fresh CSRF token) reboots the
        // kernel and wipes the message logger's history.
        $firstMessage = self::getMailerMessage();
        self::assertNotNull($firstMessage);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $firstMessage);
        $firstHtml = (string) $firstMessage->getHtmlBody();
        $firstResetUrl = $this->extractResetUrl($firstHtml);
        $firstLinkPath = parse_url($firstResetUrl, \PHP_URL_PATH);
        self::assertIsString($firstLinkPath);

        $secondToken = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-reissue@example.com', '_token' => $secondToken],
        ]);
        self::assertResponseRedirects('/forgot-password/sent');

        // The FIRST mail's link, presented after the second issuance, must now be dead.
        $this->client->request('GET', $firstLinkPath);
        self::assertResponseRedirects('/forgot-password');
        $crawler = $this->client->followRedirect();
        self::assertSame(
            'That link is no longer valid. Enter your address and we will send a new one.',
            trim($crawler->filter('.flash-error')->text()),
        );
    }

    /**
     * AC-8: the 6th POST from one client IP within the rolling hour is refused with 429 before the
     * controller body runs, carrying a real `Retry-After` header. Distinct addresses across the six
     * attempts isolate the IP limiter from the per-account domain cap (AC-7), which is a different
     * limiter entirely.
     */
    public function testTheSixthPostFromOneIpIsRefusedWith429AndARetryAfterHeader(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $token = $this->fetchForgotPasswordCsrfToken();
            $this->client->request('POST', '/forgot-password', [
                'forgot_password_form' => ['email' => \sprintf('forgot-ip-limit-%d@example.com', $attempt), '_token' => $token],
            ]);
            self::assertResponseRedirects('/forgot-password/sent', message: \sprintf('Attempt %d should not yet be IP-throttled.', $attempt));
        }

        $token = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-ip-limit-6@example.com', '_token' => $token],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * A missing/tampered CSRF token gives a 422 form error, never a 500 and never a silent success —
     * and, critically, no mail. `RegistrationControllerTest` and `EmailVerificationResendTest`
     * establish the same convention for the other two public forms in this context.
     */
    public function testATamperedCsrfTokenIsRejectedWith422AndNoMutation(): void
    {
        UserFactory::createOne(['email' => Email::fromString('forgot-csrf-check@example.com')]);

        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-csrf-check@example.com', '_token' => 'not-a-valid-token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEmailCount(0);
    }

    /**
     * The fifth way the neutral response is reached (Finding 8), and the point of the catch: a mail
     * transport that refuses a **known** address's message must still be indistinguishable from an
     * **unknown** address's ordinary neutral response — without that catch arm a known address 500s
     * where an unknown one 302s, which is a cleaner enumeration oracle than any timing channel.
     *
     * Both cases are driven through **one client with one container override**, exactly like the
     * comparison AC-5's own test makes, so the two responses are asserted against each other rather
     * than against two copies of a remembered literal.
     *
     * TWO GOTCHAS, EACH FAILS SILENTLY ALONE (CLAUDE.md). First, the override must target the
     * **concrete** service id (`TwigPasswordResetMailer::class`), never the `PasswordResetMailer`
     * port alias — Symfony's `ResolveReferencesToAliasesPass` has already rewritten
     * `RequestPasswordResetHandler`'s constructor argument to the concrete id at compile time, so
     * overriding the alias changes what asking the container for the port would return without
     * touching what was actually injected. Second, `KernelBrowser` reboots the kernel (and rebuilds
     * the container, discarding the override) before every request unless `disableReboot()` is
     * called first — and it must be called before the *first* request this test makes, including the
     * GET that fetches a CSRF token, not merely before the POST.
     *
     * `ThrowingPasswordResetMailer` throws a genuine `Symfony\Component\Mailer\Exception\TransportException`
     * — the exact type `PasswordResetController::request()`'s `MailTransportFailure` catch arm names
     * — rather than a bare `\RuntimeException`, so this test actually exercises that arm instead of
     * an uncaught exception producing an unrelated 500.
     *
     * The row-count assertion is the other half of the failure contract this catch exists to keep:
     * "the request row is committed but no link was delivered", never "nothing happened at all" —
     * `RequestPasswordResetHandler` saves before it sends precisely so a link in an inbox always has
     * a row behind it, and here the mail never left, but the row must still be there for the queue's
     * retry story to mean anything.
     *
     * The only signal an operator actually gets out of this arm is the log line — the HTTP response
     * is deliberately identical to the unknown-address case — so this test also swaps the "app"
     * channel logger (`monolog.logger`, the concrete id `PasswordResetController` is compiled against;
     * see the two-gotchas note below) for `RecordingLogger` and asserts the record the catch writes:
     * `error`, not `info` (the level every other arm in this controller uses), and a context carrying
     * the exception but never the submitted address. Both are exactly what the catch's own docblock
     * argues for and neither was ever pinned before now — a regression on either stays green without
     * this assertion.
     */
    public function testAMailTransportFailureForAKnownAddressStillProducesTheNeutralResponseByteIdenticallyWithAnUnknownAddress(): void
    {
        UserFactory::createOne(['email' => Email::fromString('forgot-transport-fails@example.com')]);

        $this->client->disableReboot();
        self::getContainer()->set(TwigPasswordResetMailer::class, new ThrowingPasswordResetMailer());
        $logger = new RecordingLogger();
        self::getContainer()->set('monolog.logger', $logger);

        $rowsBefore = $this->countPasswordResetRequestRows();

        $knownToken = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-transport-fails@example.com', '_token' => $knownToken],
        ]);
        $knownResponse = $this->client->getResponse();
        $knownResult = [
            'status' => $knownResponse->getStatusCode(),
            'location' => $knownResponse->headers->get('Location'),
        ];

        // Captured before `followRedirect()` reboots-adjacent state (the message logger's history),
        // exactly as the AC-3/AC-9 tests above do.
        self::assertEmailCount(0, 'A failed transport must leave no mail behind in the message logger.');

        $knownFlash = trim($this->client->followRedirect()->filter('.flash-success')->text());

        // Save-before-send: the row is committed even though delivery failed.
        self::assertSame(
            $rowsBefore + 1,
            $this->countPasswordResetRequestRows(),
            'The request row must still be committed even though the transport refused the message.',
        );

        // The one signal an operator gets: `error`, not the `info` every other arm in this
        // controller logs at, and no submitted address anywhere in the record — only the exception,
        // which is what actually explains the failure. `assertSame` on the level (rather than a
        // helper like `hasErrorRecords()`) is what catches a silent downgrade to `info`; checking the
        // message, the `reason` string and the exception's own message is what catches the address
        // being added back in anywhere, not merely under one particular key name.
        self::assertSame(1, $logger->count(), 'Expected exactly one log record from the transport-failure arm.');
        $record = $logger->last();
        self::assertSame('error', $record['level'], 'A mail transport failure on the reset flow must log at error, not info.');

        $context = $record['context'];
        self::assertArrayHasKey('reason', $context);
        self::assertArrayHasKey('exception', $context);
        $reason = $context['reason'];
        $recordedException = $context['exception'];
        self::assertIsString($reason);
        self::assertInstanceOf(\Throwable::class, $recordedException);

        self::assertStringNotContainsString('forgot-transport-fails@example.com', $record['message']);
        self::assertStringNotContainsString('forgot-transport-fails@example.com', $reason);
        self::assertStringNotContainsString('forgot-transport-fails@example.com', $recordedException->getMessage());

        $unknownToken = $this->fetchForgotPasswordCsrfToken();
        $this->client->request('POST', '/forgot-password', [
            'forgot_password_form' => ['email' => 'forgot-transport-fails-unknown@example.com', '_token' => $unknownToken],
        ]);
        $unknownResponse = $this->client->getResponse();
        $unknownResult = [
            'status' => $unknownResponse->getStatusCode(),
            'location' => $unknownResponse->headers->get('Location'),
        ];
        $unknownFlash = trim($this->client->followRedirect()->filter('.flash-success')->text());

        self::assertSame($knownResult['status'], $unknownResult['status'], 'Status differed between the transport-failure case and the unknown-address case.');
        self::assertSame($knownResult['location'], $unknownResult['location'], 'Location differed between the transport-failure case and the unknown-address case.');
        self::assertSame($knownFlash, $unknownFlash, 'Flash differed between the transport-failure case and the unknown-address case.');

        // The concrete shape of the shared outcome, so a regression that broke both cases
        // identically (leaving the comparison above green) still fails this test.
        self::assertSame(Response::HTTP_FOUND, $knownResult['status']);
        self::assertSame('/forgot-password/sent', $knownResult['location']);
    }

    private function fetchForgotPasswordCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $token = $crawler->filter('input[name="forgot_password_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    private function countPasswordResetRequestRows(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_password_reset_request');

        if (!is_numeric($count)) {
            self::fail('Expected COUNT(*) to return a numeric value.');
        }

        return (int) $count;
    }

    /**
     * Extracts the absolute reset URL from a rendered mail body, failing loudly rather than
     * returning an unreliable offset if the mail did not contain one.
     */
    private function extractResetUrl(string $html): string
    {
        self::assertMatchesRegularExpression(
            '#https?://[a-zA-Z0-9.\-:]+/reset-password/[A-Za-z0-9_-]{43}#',
            $html,
            'Expected the mail to contain an absolute reset link.',
        );

        preg_match('#(https?://[a-zA-Z0-9.\-:]+/reset-password/[A-Za-z0-9_-]{43})#', $html, $matches);

        if (!isset($matches[1])) {
            self::fail('Expected a capture group for the reset URL.');
        }

        return $matches[1];
    }
}
