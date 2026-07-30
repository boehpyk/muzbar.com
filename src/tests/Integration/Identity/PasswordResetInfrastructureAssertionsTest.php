<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Port\PasswordResetMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\ResetToken;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Infrastructure assertions for `identity-password-reset` (T42): the query plans behind the three
 * new repository methods (AC-43), the reset mail's URL generation outside an HTTP request (AC-11),
 * and the bundle-decision checkable by grep (AC-38).
 *
 * ADDED BESIDE `InfrastructureAssertionsTest` RATHER THAN EXTENDING IT — a deliberate choice, not an
 * oversight. That file's own assertions belong to slices 1 and 2 (the login lookup, the Redis wiring,
 * `identity_email_verification_request`'s indexes, the verification mail); nothing here needs any of
 * its setup or helpers beyond the same `planTextOf()`-shaped EXPLAIN-reading technique, which is
 * reproduced locally rather than inherited so this file has no coupling to a class outside this
 * slice's own boundary. A new slice's infrastructure assertions living in a new file mirrors how every
 * other kind of test in this suite is already organised — one file per feature, not one ever-growing
 * shared file — and avoids modifying a file this slice did not otherwise need to touch.
 */
final class PasswordResetInfrastructureAssertionsTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    /**
     * AC-43: `EXPLAIN` of the digest lookup (`WHERE token_hash = $1`) — the query behind
     * `findByTokenHash()`, run once per click on a reset link — shows an Index Scan on
     * `uniq_identity_password_reset_request_token_hash`, never a Seq Scan.
     *
     * Seeds real volume rather than `SET enable_seqscan = off`, for the same reason
     * `InfrastructureAssertionsTest` gives: disabling sequential scans would prove the index merely
     * *exists*, not that Postgres' cost-based planner genuinely prefers it. An explicit `ANALYZE`
     * follows the bulk insert because DAMA wraps this test in one transaction, so without it
     * `pg_statistic` would still describe the table as it was before this test's rows existed.
     *
     * WHAT THIS PROVES AND WHAT IT DOES NOT. It proves the planner chooses the named index for this
     * shape of query today, on a table seeded large enough for the choice to be a real cost decision
     * rather than an artefact of a tiny table (where Postgres will happily prefer a sequential scan
     * regardless of what indexes exist, because scanning ten rows is cheaper than consulting a
     * B-tree). It does not prove anything about production data volumes or the 200 ms search budget —
     * that budget is Constitution-scoped to faceted search (Phase 2) and does not apply here (AC-43's
     * own wording).
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
            INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now(),
                now() + interval '1 hour',
                NULL,
                NULL
            FROM generate_series(1, 20000) gs
            SQL);

        $targetHash = hash('sha256', random_bytes(32));
        $connection->executeStatement(
            'INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at) VALUES (gen_random_uuid(), gen_random_uuid(), ?, now(), now() + interval \'1 hour\', NULL, NULL)',
            [$targetHash],
        );

        $connection->executeStatement('ANALYZE identity_password_reset_request');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at FROM identity_password_reset_request WHERE token_hash = ?',
            [$targetHash],
        );

        $planText = $this->planTextOf($plan);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('uniq_identity_password_reset_request_token_hash', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-43: `EXPLAIN` of the anti-abuse count (`WHERE user_id = $1 AND issued_at >= $2`) — the
     * query behind `countIssuedForUserSince()` — shows an Index Scan on
     * `idx_identity_password_reset_request_user_issued`, the composite index whose column order
     * (`user_id` leading, `issued_at` trailing) is the entire reason it is named explicitly in the
     * mapping rather than left to Doctrine's generated name.
     */
    public function testExplainOnTheAntiAbuseCountUsesAnIndexScanNotASeqScan(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now() - (gs || ' seconds')::interval,
                now() + interval '1 hour',
                NULL,
                NULL
            FROM generate_series(1, 20000) gs
            SQL);

        $targetUserId = $this->fetchRandomUuid($connection);
        $since = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
        $connection->executeStatement(
            'INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at) VALUES (gen_random_uuid(), ?, ?, now(), now() + interval \'1 hour\', NULL, NULL)',
            [$targetUserId, hash('sha256', random_bytes(32))],
        );

        $connection->executeStatement('ANALYZE identity_password_reset_request');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT COUNT(id) FROM identity_password_reset_request WHERE user_id = ? AND issued_at >= ?',
            [$targetUserId, $since],
        );

        $planText = $this->planTextOf($plan);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('idx_identity_password_reset_request_user_issued', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-43: `EXPLAIN` of the outstanding-requests sweep (`WHERE user_id = $1 AND redeemed_at IS
     * NULL AND invalidated_at IS NULL`) — the query behind `findOutstandingForUser()` — also rides
     * the same composite index, on its leading column alone. This is the query
     * `RequestPasswordResetHandler`'s reissue sweep (AC-9) runs on every issuance, so it is the one
     * of the three that runs most often.
     */
    public function testExplainOnTheOutstandingRequestsSweepUsesAnIndexScanNotASeqScan(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement(<<<'SQL'
            INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now(),
                now() + interval '1 hour',
                NULL,
                NULL
            FROM generate_series(1, 20000) gs
            SQL);

        $targetUserId = $this->fetchRandomUuid($connection);
        $connection->executeStatement(
            'INSERT INTO identity_password_reset_request (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at) VALUES (gen_random_uuid(), ?, ?, now(), now() + interval \'1 hour\', NULL, NULL)',
            [$targetUserId, hash('sha256', random_bytes(32))],
        );

        $connection->executeStatement('ANALYZE identity_password_reset_request');

        $plan = $connection->fetchAllAssociative(
            'EXPLAIN SELECT id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at FROM identity_password_reset_request WHERE user_id = ? AND redeemed_at IS NULL AND invalidated_at IS NULL',
            [$targetUserId],
        );

        $planText = $this->planTextOf($plan);

        self::assertStringContainsString('Index Scan', $planText);
        self::assertStringContainsString('idx_identity_password_reset_request_user_issued', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-11: the reset mail renders a correct absolute URL when built with **no HTTP request in
     * flight** — exactly the situation `TwigPasswordResetMailer` runs in for real, since
     * `SendEmailMessage` goes through Messenger (ADR-0010) and it is the **worker** that actually
     * renders and sends. `KernelTestCase`, not `WebTestCase`: no `createClient()` is ever called, so
     * nothing is ever pushed onto the `RequestStack`, which is the "no request context" this test
     * exists to prove rather than simulate.
     *
     * Mirrors `InfrastructureAssertionsTest::testTheVerificationMailRendersACorrectAbsoluteUrlWithNoRequestContext()`
     * and inherits its gotcha and its honestly-stated limits: the expectation is sourced from the
     * `DEFAULT_URI` env var itself (`.env.test` pins it to `http://localhost`), never from
     * `RequestContext`, because the generator and the context are compiled from the same value and
     * comparing one against the other would let a `DEFAULT_URI` regression to the Symfony skeleton's
     * default pass unnoticed — both sides would agree on the wrong answer. Precisely because
     * `.env.test`'s value happens to match FrameworkBundle's own fallback host/scheme, this test
     * cannot detect a regression to that specific wrong value, and cannot detect the `default_uri`
     * wiring disappearing outright — both would still produce a byte-identical link. What it does
     * catch, and all it claims to: a regression from `ABSOLUTE_URL` to `ABSOLUTE_PATH`, a wrong route,
     * or the token going missing from the body.
     */
    public function testTheResetMailRendersACorrectAbsoluteUrlWithNoRequestContext(): void
    {
        self::bootKernel();

        $defaultUri = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? null;
        self::assertIsString($defaultUri, 'Expected DEFAULT_URI to be present in the test environment (see .env.test).');

        $parsedDefaultUri = parse_url($defaultUri);
        self::assertIsArray($parsedDefaultUri, \sprintf('Expected DEFAULT_URI ("%s") to be a parseable URI.', $defaultUri));

        $expectedScheme = $parsedDefaultUri['scheme'] ?? null;
        $expectedHost = $parsedDefaultUri['host'] ?? null;
        self::assertIsString($expectedScheme, \sprintf('Expected DEFAULT_URI ("%s") to carry a scheme.', $defaultUri));
        self::assertIsString($expectedHost, \sprintf('Expected DEFAULT_URI ("%s") to carry a host.', $defaultUri));

        $mailer = self::getContainer()->get(PasswordResetMailer::class);
        self::assertInstanceOf(PasswordResetMailer::class, $mailer);

        $token = ResetToken::fromString(str_repeat('a', ResetToken::LENGTH));
        $expiresAt = new \DateTimeImmutable('2026-07-29T10:00:00+00:00');

        $mailer->sendResetLink(Email::fromString('no-request-context-reset@example.com'), $token, $expiresAt);

        $message = self::getMailerMessage();
        self::assertNotNull($message);

        $expectedUrl = \sprintf('%s://%s/reset-password/%s', $expectedScheme, $expectedHost, $token->reveal());
        self::assertEmailTextBodyContains($message, $expectedUrl);
        self::assertEmailHtmlBodyContains($message, $expectedUrl);
    }

    /**
     * AC-38 (the bundle decision, made checkable): `symfonycasts/reset-password-bundle` appears in
     * **neither** `composer.json` nor `composer.lock`, so ADR-0011's decision not to install it
     * cannot quietly erode into "somebody installed it to try something" without a test noticing.
     */
    public function testTheResetPasswordBundleIsAbsentFromComposerJsonAndComposerLock(): void
    {
        self::bootKernel();

        if (!self::$kernel instanceof KernelInterface) {
            self::fail('Expected the kernel to be booted.');
        }

        $projectDir = self::$kernel->getProjectDir();

        $composerJson = file_get_contents($projectDir.'/composer.json');
        self::assertIsString($composerJson);
        self::assertStringNotContainsString('symfonycasts/reset-password-bundle', $composerJson);

        $composerLock = file_get_contents($projectDir.'/composer.lock');
        self::assertIsString($composerLock);
        self::assertStringNotContainsString('symfonycasts/reset-password-bundle', $composerLock);
    }

    private function fetchRandomUuid(Connection $connection): string
    {
        $uuid = $connection->fetchOne('SELECT gen_random_uuid()');

        if (!\is_string($uuid)) {
            self::fail('Expected gen_random_uuid() to return a string.');
        }

        return $uuid;
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
