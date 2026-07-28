<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Port\VerificationMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;
use App\Tests\Factory\EmailVerificationRequestFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

/**
 * AC-15, AC-18, AC-32, AC-37, AC-6: infrastructure assertions that do not fit the
 * Domain/Application/HTTP shape of the other test files — the login lookup's query plan, the Redis
 * wiring behind the rate limiter and the session handler, `identity-email-verification`'s two new
 * indexes, and the verification mail's URL generation outside an HTTP request.
 */
final class InfrastructureAssertionsTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    /**
     * AC-32: `EXPLAIN` of the login lookup shows an Index Scan on `uniq_identity_user_email`,
     * never a Seq Scan.
     *
     * This is deliberately NOT `SET enable_seqscan = off` (the brief explicitly rules that out —
     * it would prove the index merely *exists*, not that the planner genuinely prefers it). Instead
     * this seeds enough real rows that Postgres' cost-based planner picks the index on its own
     * merits, then runs `ANALYZE` so the planner's statistics reflect the rows just inserted (DAMA
     * wraps the whole test in one transaction; without an explicit `ANALYZE`, `pg_statistic` would
     * still describe the empty table and the planner would have no reason to prefer the index).
     */
    public function testExplainOnTheLoginLookupUsesAnIndexScanNotASeqScan(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        // Bulk SQL rather than the Foundry factory: this needs volume (tens of thousands of rows)
        // fast enough to run inside a single test, not aggregate-faithful construction.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO identity_user (id, email, password_hash, roles, email_verified_at, registered_at)
            SELECT
                gen_random_uuid(),
                'planner-seed-' || gs || '@example.com',
                '$2y$04$0000000000000000000000',
                '["ROLE_USER"]',
                NULL,
                now()
            FROM generate_series(1, 20000) gs
            SQL);

        // "-target" rather than a bare number: the bulk insert above already fills
        // `planner-seed-1@example.com` .. `planner-seed-20000@example.com`, and a numeric target
        // could collide with the unique index it is meant to exercise.
        $target = 'planner-seed-target@example.com';
        UserFactory::createOne(['email' => Email::fromString($target)]);

        $connection->executeStatement('ANALYZE identity_user');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT id, email, password_hash, roles, email_verified_at, registered_at FROM identity_user WHERE email = ?',
            [$target],
        );

        $lines = [];
        foreach ($plan as $row) {
            $line = $row['QUERY PLAN'] ?? reset($row);

            if (!\is_string($line)) {
                self::fail('Expected each EXPLAIN row to contain a string plan line.');
            }

            $lines[] = $line;
        }
        $planText = implode("\n", $lines);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('uniq_identity_user_email', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-15: the throttle counters live in the Redis-backed `cache.rate_limiter` pool, not on the
     * container filesystem — so a deploy or container restart cannot silently reset the
     * brute-force budget.
     */
    public function testTheRateLimiterCachePoolResolvesToARedisAdapter(): void
    {
        self::bootKernel();

        $pool = self::getContainer()->get('cache.rate_limiter');

        self::assertInstanceOf(RedisAdapter::class, $pool);
    }

    /**
     * AC-18 (wiring half): `RedisSessionHandler` is registered and correctly fed the shared
     * `Predis\Client` service — provable inside the test kernel regardless of which storage
     * factory is active for the test *environment* (which deliberately uses `mock_file`, see
     * `config/packages/framework.yaml`'s `when@test` block, so that tests need no session cookie
     * round-trip and no running Redis for the session store specifically).
     */
    public function testTheRedisSessionHandlerServiceIsRegisteredAndWired(): void
    {
        self::bootKernel();

        $handler = self::getContainer()->get(RedisSessionHandler::class);

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    /**
     * AC-18 (live half): outside the test environment — where `framework.yaml`'s `when@test`
     * override does not apply — the actual active session handler alias resolves to
     * `RedisSessionHandler`, proven by asking the dev container's own DI graph rather than
     * asserting against a copy of the YAML.
     */
    public function testTheActiveSessionHandlerIsRedisOutsideTheTestEnvironment(): void
    {
        self::bootKernel();

        if (!self::$kernel instanceof KernelInterface) {
            self::fail('Expected the kernel to be booted.');
        }

        $projectDir = self::$kernel->getProjectDir();

        $process = new Process(
            [\PHP_BINARY, 'bin/console', 'debug:container', 'session.handler', '--env=dev', '--no-debug'],
            $projectDir,
        );
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString(
            'Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler',
            $process->getOutput(),
        );
    }

    /**
     * AC-37: `EXPLAIN` of the redemption lookup (`WHERE token_hash = $1`) shows an Index Scan on
     * `uniq_identity_email_verification_request_token_hash`, never a Seq Scan — same technique as
     * the login-lookup assertion above: enough real rows that the cost-based planner prefers the
     * index on its own merits, then an explicit `ANALYZE` so `pg_statistic` reflects the rows just
     * inserted inside this test's DAMA transaction.
     */
    public function testExplainOnTheTokenHashLookupUsesAnIndexScanNotASeqScan(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        // Bulk SQL rather than the Foundry factory: this needs volume fast, not aggregate-faithful
        // construction. `md5()` needs no extension (unlike `gen_random_bytes()`/pgcrypto) and is
        // unique enough per row for this seed.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO identity_email_verification_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at)
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now(),
                now() + interval '1 day',
                NULL
            FROM generate_series(1, 20000) gs
            SQL);

        $target = EmailVerificationRequestFactory::createOne();
        $targetHash = $target->tokenHash()->toString();

        $connection->executeStatement('ANALYZE identity_email_verification_request');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT id, user_id, token_hash, issued_at, expires_at, redeemed_at FROM identity_email_verification_request WHERE token_hash = ?',
            [$targetHash],
        );

        $planText = $this->planTextOf($plan);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('uniq_identity_email_verification_request_token_hash', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-37: `EXPLAIN` of the anti-abuse count (`WHERE user_id = $1 AND issued_at >= $2`) shows an
     * Index Scan on `idx_identity_email_verification_request_user_issued` — the composite index
     * whose column order (`user_id` leading, `issued_at` trailing) is the whole point of naming it
     * explicitly in the mapping rather than leaving it to Doctrine's generated name.
     */
    public function testExplainOnTheAntiAbuseCountUsesAnIndexScanNotASeqScan(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO identity_email_verification_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at)
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now() - (gs || ' seconds')::interval,
                now() + interval '1 day',
                NULL
            FROM generate_series(1, 20000) gs
            SQL);

        $target = EmailVerificationRequestFactory::createOne();
        $targetUserId = $target->userId()->toString();
        $since = $target->issuedAt()->modify('-1 hour')->format(\DateTimeInterface::ATOM);

        $connection->executeStatement('ANALYZE identity_email_verification_request');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT COUNT(id) FROM identity_email_verification_request WHERE user_id = ? AND issued_at >= ?',
            [$targetUserId, $since],
        );

        $planText = $this->planTextOf($plan);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('idx_identity_email_verification_request_user_issued', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-6, the assertion the technical plan calls out as the one that matters most: the mail
     * renders a correct absolute URL when built with **no HTTP request in flight** — the exact
     * situation the Messenger worker and `bin/console` are always in (`when@test` routes
     * `SendEmailMessage` to `sync`, so this send happens inline, inside this method, without a
     * `KernelBrowser` ever having created a request). `TwigVerificationMailer`'s own docblock
     * explains the footgun this guards: with no request to ask, `UrlGeneratorInterface` falls back
     * to `framework.router.default_uri` (`DEFAULT_URI`), and a misconfigured value there produces a
     * verification link pointing at `http://localhost` in production with no test inside an HTTP
     * request ever able to catch it. This test is deliberately the one kind of test that can.
     *
     * `KernelTestCase`, not `WebTestCase` — no `createClient()` is ever called, so no request is
     * ever pushed onto the `RequestStack`. That absence *is* the "no request context" this test
     * exists to prove; simulating it with a mock would only prove the mock behaves as configured.
     *
     * THE GOTCHA THIS TEST HIT ONCE AND MUST NOT HIT AGAIN. The first version of this assertion
     * asked the `RequestContext` service — the very object `UrlGeneratorInterface` builds the link
     * from — for its own scheme and host, and compared the mailer's output against that. That is
     * circular: the context and the generator are fed by the *same* compiled `DEFAULT_URI`, so a
     * `DEFAULT_URI` left at the Symfony skeleton's default `http://localhost` (exactly the
     * production footgun this AC exists to catch, per CLAUDE.md's infrastructure-footguns section)
     * would still make the assertion pass — both sides would agree on the wrong value. The fix is to
     * source the expectation from somewhere the generator's wiring cannot also feed: `.env.test`
     * itself, read back via the superglobals `tests/bootstrap.php`'s `Dotenv::bootEnv()` populates
     * (`$container->getParameter('env(DEFAULT_URI)')` throws `ParameterNotFoundException` — verified
     * empirically — because nothing in this container graph binds that placeholder to a standalone
     * parameter id; only `router.request_context.base_url`, the same compiled value, does).
     *
     * WHAT THIS ASSERTION CAN AND CANNOT CATCH — worth being precise about, and narrower than it
     * looks. `.env.test:19` pins `DEFAULT_URI` to `http://localhost`, and FrameworkBundle's own
     * fallback (`Resources/config/routing.php`: `router.request_context.host = 'localhost'`,
     * `scheme = 'http'`) is the *same value*. So this test cannot detect a regression to
     * `http://localhost` — and it equally cannot detect the `default_uri` wiring disappearing
     * altogether, because the generator would then fall back to `localhost`/`http` and produce a
     * byte-identical link. Both sides would agree, green, for the wrong reason.
     *
     * What it genuinely buys, and all it buys: a regression from `ABSOLUTE_URL` to `ABSOLUTE_PATH`
     * (a relative link in an email, which is unclickable), a wrong route or path, and the token
     * going missing from the body. Those are real and worth a test.
     *
     * Catching a *wrong host* — the production footgun CLAUDE.md names — needs an environment whose
     * `DEFAULT_URI` diverges from the framework fallback, which by construction this one is not.
     * That gap is structural, not an oversight: do not "strengthen" this assertion without first
     * changing what `.env.test` pins, or it will start claiming coverage it cannot have.
     */
    public function testTheVerificationMailRendersACorrectAbsoluteUrlWithNoRequestContext(): void
    {
        self::bootKernel();

        // Read the *env var*, not the router's derived `RequestContext` — see the gotcha above.
        // `tests/bootstrap.php` already ran `Dotenv::bootEnv()` before PHPUnit booted anything, so
        // `.env.test`'s `DEFAULT_URI` is sitting in both superglobals by the time this method runs.
        $defaultUri = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? null;
        self::assertIsString($defaultUri, 'Expected DEFAULT_URI to be present in the test environment (see .env.test).');

        $parsedDefaultUri = parse_url($defaultUri);
        self::assertIsArray($parsedDefaultUri, \sprintf('Expected DEFAULT_URI ("%s") to be a parseable URI.', $defaultUri));

        // `?? null` plus `assertIsString` rather than `assertArrayHasKey` followed by direct offset
        // access: PHPStan's shape for `parse_url()`'s return type marks every key optional, and
        // `assertArrayHasKey` does not narrow that away, so a direct `$parsedDefaultUri['scheme']`
        // read after it still reports "might not exist" at max level.
        $expectedScheme = $parsedDefaultUri['scheme'] ?? null;
        $expectedHost = $parsedDefaultUri['host'] ?? null;
        self::assertIsString($expectedScheme, \sprintf('Expected DEFAULT_URI ("%s") to carry a scheme.', $defaultUri));
        self::assertIsString($expectedHost, \sprintf('Expected DEFAULT_URI ("%s") to carry a host.', $defaultUri));

        $mailer = self::getContainer()->get(VerificationMailer::class);
        self::assertInstanceOf(VerificationMailer::class, $mailer);

        $token = VerificationToken::fromString(str_repeat('a', VerificationToken::LENGTH));
        $expiresAt = new \DateTimeImmutable('2026-07-27T10:00:00+00:00');

        $mailer->sendVerificationLink(Email::fromString('no-request-context@example.com'), $token, $expiresAt);

        $message = self::getMailerMessage();
        self::assertNotNull($message);

        // `assertEmailTextBodyContains`/`assertEmailHtmlBodyContains` decode the MIME
        // quoted-printable transfer encoding before matching, unlike a raw substring search against
        // `$message->toString()` — the wire format soft-wraps long lines with `=\r\n` continuations,
        // which would otherwise split this URL across two lines and fail a naive `str_contains`
        // even though the rendered, decoded mail is correct.
        $expectedUrl = \sprintf('%s://%s/verify-email/%s', $expectedScheme, $expectedHost, $token->reveal());
        self::assertEmailTextBodyContains($message, $expectedUrl);
        self::assertEmailHtmlBodyContains($message, $expectedUrl);
    }

    /**
     * @param list<array<string, mixed>> $plan
     */
    private function planTextOf(array $plan): string
    {
        $lines = [];
        foreach ($plan as $row) {
            $line = $row['QUERY PLAN'] ?? reset($row);

            if (!\is_string($line)) {
                self::fail('Expected each EXPLAIN row to contain a string plan line.');
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
