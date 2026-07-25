<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

/**
 * AC-15, AC-18, AC-32: infrastructure assertions that do not fit the Domain/Application/HTTP
 * shape of the other test files — the login lookup's query plan, and the Redis wiring behind the
 * rate limiter and the session handler.
 */
final class InfrastructureAssertionsTest extends KernelTestCase
{
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
}
