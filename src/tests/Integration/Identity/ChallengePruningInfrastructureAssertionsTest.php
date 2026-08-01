<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\PruneExpiredChallenges;
use App\Application\Identity\Handler\PruneExpiredChallengesHandler;
use App\Infrastructure\Identity\Persistence\Doctrine\ChallengeIntegrityProbe;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Infrastructure assertions for `identity-challenge-pruning`: the query plans behind the two new
 * indexes, and the four decisions that would otherwise erode quietly because nothing in the
 * application would notice.
 *
 * ADDED BESIDE `InfrastructureAssertionsTest` AND `PasswordResetInfrastructureAssertionsTest` rather
 * than extending either, for the reason the latter already gives in its own header: one file per
 * feature, not one ever-growing shared file, and no coupling to a class outside this slice's
 * boundary. The `planTextOf()` technique is reproduced locally on the same grounds.
 *
 * The greps at the bottom are cheap and are not padding. Each one guards a decision that a future
 * reader could undo in good faith — a shared `Challenge` type, a SQL string drifting up into the
 * Application layer, `symfony/scheduler` arriving with some unrelated bundle — and none of those
 * would fail a test, break a build, or look wrong in a diff.
 */
final class ChallengePruningInfrastructureAssertionsTest extends KernelTestCase
{
    /**
     * AC-37: `EXPLAIN` shows an **Index Scan** on `idx_identity_email_verification_request_expires_at`
     * for the overdue **count**.
     *
     * Seeds real volume rather than `SET enable_seqscan = off`, following this suite's existing rule:
     * disabling sequential scans would prove the index *exists*, not that Postgres' cost-based planner
     * genuinely prefers it — and on a ten-row table the planner is right to prefer a scan. `ANALYZE`
     * follows the insert because DAMA wraps the test in one transaction, so `pg_statistic` would
     * otherwise still describe the table as it was before these rows existed.
     *
     * The justification for the index is the **batching**, not the table size: a fifty-batch run
     * without it is fifty sequential scans, every hour.
     */
    public function testTheOverdueCountUsesTheExpiresAtIndexOnTheVerificationTable(): void
    {
        $connection = $this->seededConnection('identity_email_verification_request');

        $planText = $this->planTextOf($connection->fetchAllAssociative(
            "EXPLAIN SELECT COUNT(id) FROM identity_email_verification_request WHERE expires_at < now() - interval '30 days'",
        ));

        self::assertStringContainsString('idx_identity_email_verification_request_expires_at', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-37: the same for the reset table's overdue count.
     */
    public function testTheOverdueCountUsesTheExpiresAtIndexOnTheResetTable(): void
    {
        $connection = $this->seededConnection('identity_password_reset_request');

        $planText = $this->planTextOf($connection->fetchAllAssociative(
            "EXPLAIN SELECT COUNT(id) FROM identity_password_reset_request WHERE expires_at < now() - interval '30 days'",
        ));

        self::assertStringContainsString('idx_identity_password_reset_request_expires_at', $planText);
        self::assertStringNotContainsString('Seq Scan', $planText);
    }

    /**
     * AC-37: `EXPLAIN` on the **selection subquery inside the delete** — the half that actually
     * matters, and the half a count-only assertion would miss.
     *
     * This is the statement the sweep runs up to fifty times per table per run, and it is the one
     * whose `ORDER BY expires_at LIMIT n` the index answers by walking its own leading edge. A plan
     * containing a **Sort** node here would mean the index is being read and then re-sorted, which is
     * the cost the `ORDER BY` was supposed to make free.
     */
    public function testTheDeleteSelectionSubqueryUsesTheExpiresAtIndexOnBothTables(): void
    {
        foreach ([
            'identity_email_verification_request' => 'idx_identity_email_verification_request_expires_at',
            'identity_password_reset_request' => 'idx_identity_password_reset_request_expires_at',
        ] as $table => $index) {
            $connection = $this->seededConnection($table);

            $planText = $this->planTextOf($connection->fetchAllAssociative(\sprintf(
                "EXPLAIN SELECT id FROM %s WHERE expires_at < now() - interval '30 days' ORDER BY expires_at LIMIT 1000",
                $table,
            )));

            self::assertStringContainsString($index, $planText, \sprintf('%s must ride its expires_at index.', $table));
            self::assertStringNotContainsString('Seq Scan', $planText);
            self::assertStringNotContainsString(
                'Sort ',
                $planText,
                \sprintf('%s: an explicit Sort means ORDER BY expires_at is not free, which was the whole reason for the index.', $table),
            );
        }
    }

    /**
     * AC-8: over a **complete run**, the sweep issues **no write** to `identity_user`, and no read of
     * it other than the orphan probe's two anti-joins.
     *
     * WHY THIS IS WORTH A TEST WHEN NOTHING IN THE CODE GOES NEAR `identity_user`. Precisely because
     * nothing does — today. Both challenge tables carry a notion of "finished" that is only visible in
     * the *user's* row (`email_verified_at` for one, `password_changed_at` for the other), and those
     * two facts are the exact road by which a future "improvement" would acquire a join: *"surely we
     * can drop verification rows once the address is verified?"* This assertion is what makes that
     * change fail rather than ship. It is invariant I-27, which is not an invariant of anything — it
     * is a structural fact held by the absence of a write path, and an absence needs a test more than
     * a presence does.
     *
     * Read from `doctrine.debug_data_holder`, DoctrineBundle's real query log, so it observes what was
     * actually sent to Postgres rather than what the source appears to say.
     */
    public function testACompleteRunNeverWritesToIdentityUserAndOnlyReadsItViaTheProbe(): void
    {
        self::bootKernel();

        $debug = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debug);

        $handler = self::getContainer()->get(PruneExpiredChallengesHandler::class);
        self::assertInstanceOf(PruneExpiredChallengesHandler::class, $handler);

        $probe = self::getContainer()->get(ChallengeIntegrityProbe::class);
        self::assertInstanceOf(ChallengeIntegrityProbe::class, $probe);

        $debug->reset();

        $handler(new PruneExpiredChallenges(null, false));
        $probe->countOrphanedEmailVerificationRequests();
        $probe->countOrphanedPasswordResetRequests();

        $statements = [];
        foreach ($debug->getData() as $queries) {
            foreach ($queries as $query) {
                $sql = $query['sql'] ?? null;

                if (\is_string($sql)) {
                    $statements[] = $sql;
                }
            }
        }

        self::assertNotEmpty($statements, 'If no statement were captured, every assertion below would be vacuous.');

        $touchingUser = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'identity_user'),
        ));

        foreach ($touchingUser as $sql) {
            self::assertMatchesRegularExpression(
                '/^\s*SELECT\b/i',
                $sql,
                \sprintf('A run must never write to identity_user. Offending statement: %s', $sql),
            );
            self::assertStringContainsString(
                'NOT EXISTS',
                $sql,
                \sprintf('The only permitted read of identity_user is the orphan probe\'s anti-join. Offending statement: %s', $sql),
            );
        }

        // The probe ran twice above, so exactly two statements may mention the user table. A third
        // would mean the sweep itself acquired one.
        self::assertCount(2, $touchingUser, 'Only the probe\'s two anti-joins may mention identity_user.');
    }

    /**
     * AC-28: **no foreign key** exists on either challenge table. ADR-0009 decision 4 stands — this
     * slice *discharges* its orphan clause rather than reversing it.
     *
     * Read from the live catalogue rather than by grepping the migration, because the question is
     * what the database actually has: an FK added by hand, by a later migration, or by a mapping that
     * grew an association would all be invisible to a grep over this slice's own migration file.
     */
    public function testNeitherChallengeTableHasAForeignKey(): void
    {
        $connection = $this->connection();

        /** @var int|numeric-string $count */
        $count = $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
              FROM information_schema.table_constraints
             WHERE constraint_type = 'FOREIGN KEY'
               AND table_name IN ('identity_email_verification_request', 'identity_password_reset_request')
            SQL);

        self::assertSame(0, (int) $count, 'Referential integrity between aggregate roots is the application\'s job (ADR-0009 decision 4).');
    }

    /**
     * AC-35: `symfony/scheduler` is not an **installed package**.
     *
     * READ THE LOCK'S PACKAGE LISTS, NEVER GREP THE FILE — and this is a corrected criterion rather
     * than a stylistic preference. `grep symfony/scheduler composer.lock` returns two hits on a clean
     * checkout, both inside `symfony/framework-bundle`'s `conflict` block, so the grep form of this
     * assertion **fails before any code is written**. That is the mirror image of this repository's
     * no-assertion-that-cannot-fail rule: an assertion that cannot *pass* is equally useless, and
     * would have been "fixed" by deleting it.
     *
     * The decision it guards: Scheduler is a transport, a transport needs a consumer, a consumer is a
     * second daemon — and `deploy.yml` does not restart the daemon this deployment already has, so a
     * scheduler container would run whatever image it last had and delete data by a retention policy
     * nobody currently believes is in force.
     */
    public function testSymfonySchedulerIsNotAnInstalledPackage(): void
    {
        $lockPath = \dirname(__DIR__, 3).'/composer.lock';
        self::assertFileExists($lockPath);

        $lock = json_decode((string) file_get_contents($lockPath), true);
        self::assertIsArray($lock);

        $installed = [];
        foreach (['packages', 'packages-dev'] as $section) {
            /** @var list<array<string, mixed>> $packages */
            $packages = $lock[$section] ?? [];

            foreach ($packages as $package) {
                $name = $package['name'] ?? null;

                if (\is_string($name)) {
                    $installed[] = $name;
                }
            }
        }

        self::assertNotEmpty($installed, 'If the lock listed no packages at all, the assertion below could not fail.');
        self::assertNotContains('symfony/scheduler', $installed);
    }

    /**
     * AC-32: **no `Challenge` type spans the two aggregates.** The word is prose, and if it ever
     * reaches a type it has stopped being prose.
     *
     * The two use-case names that legitimately carry it — `ChallengePruningReport` and
     * `PruneExpiredChallenges` — are Application-layer names for a *run*, not abstractions over the
     * two aggregates, and the glossary permits exactly that. So this looks for a declaration whose
     * name is `Challenge` itself or begins `Challenge` followed by something that is not `Pruning` /
     * `Integrity`, which is what an abstraction would be called.
     */
    public function testNoSharedChallengeTypeExists(): void
    {
        $matches = [];

        foreach ($this->phpFilesUnder(['Domain', 'Application', 'Infrastructure']) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+Challenge(?!PruningReport|IntegrityProbe)\w*/m', $source)) {
                $matches[] = $file;
            }
        }

        self::assertSame([], $matches, 'A Challenge base class, interface or trait is the shared abstraction ADR-0012 decision 1 refused.');
    }

    /**
     * AC-34: **no SQL or DQL string appears under `Domain/` or `Application/`.**.
     *
     * The layer split made checkable. The policy (which rows qualify) lives in the Domain, the
     * workload (how much work per run) in the Application, and the mechanism (a batched set-based
     * DELETE) in Infrastructure — and the cheapest way for that to erode is a convenience query
     * drifting one layer up, where Deptrac would not see it because a string is not a dependency.
     *
     * Comments are stripped before matching: both layers discuss SQL at length in their docblocks,
     * on purpose, and this must catch a statement rather than a sentence about one.
     */
    public function testNoSqlOrDqlStringAppearsInTheDomainOrApplicationLayers(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(['Domain', 'Application']) as $file) {
            $code = $this->sourceWithoutComments((string) file_get_contents($file));

            if (preg_match('/\b(SELECT\s+\w|DELETE\s+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET)\b/i', $code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'A query in Domain/ or Application/ is the layer split eroding where Deptrac cannot see it.');
    }

    /**
     * AC-34, the other direction: the retention windows appear **only** under `Domain/`, and the
     * batch size and per-run caps **only** under `Application/`.
     *
     * Asserted both ways because either half alone is satisfiable by accident. A retention literal
     * appearing in the Application layer would mean the policy had been copied out of the aggregate
     * that owns it, and the day the aggregate changed, the copy would go on being confidently wrong.
     */
    public function testThePolicyAndWorkloadConstantsStayInTheirOwnLayers(): void
    {
        foreach ($this->phpFilesUnder(['Application']) as $file) {
            $code = $this->sourceWithoutComments((string) file_get_contents($file));

            self::assertDoesNotMatchRegularExpression('/\b(604800|2592000)\b/', $code, \sprintf(
                '%s restates a retention window. It belongs to the aggregate that owns the policy.',
                $file,
            ));
        }

        foreach ($this->phpFilesUnder(['Domain']) as $file) {
            $code = $this->sourceWithoutComments((string) file_get_contents($file));

            self::assertDoesNotMatchRegularExpression('/\bBATCH_SIZE|MAX_BATCHES_PER_TABLE|MAX_RUN_SECONDS\b/', $code, \sprintf(
                '%s names a workload constant. A domain expert has no opinion about 1000.',
                $file,
            ));
        }
    }

    /**
     * Seeds a table with enough rows for the planner's choice to be a real cost decision, and
     * `ANALYZE`s it so the statistics reflect them inside DAMA's transaction.
     */
    private function seededConnection(string $table): Connection
    {
        $connection = $this->connection();

        $extraColumns = 'identity_password_reset_request' === $table ? ', NULL, NULL' : ', NULL';
        $columns = 'identity_password_reset_request' === $table
            ? '(id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)'
            : '(id, user_id, token_hash, issued_at, expires_at, redeemed_at)';

        // SEEDED TO LOOK LIKE A HEALTHY TABLE, WHICH IS THE ONLY SHAPE THE ASSERTION IS ABOUT.
        // 20 000 live rows and a 50-row overdue tail. That ratio is not cosmetic: an index is only
        // worth reading when the predicate is *selective*, and in a healthy table almost nothing is
        // overdue precisely because the sweep runs hourly. A first draft of this seed made every row
        // expire in the past, so `expires_at < threshold` matched half the table and Postgres
        // correctly chose a Seq Scan — the planner was right and the test was wrong. Reproducing the
        // real distribution is what makes the plan assertion meaningful rather than a demand that
        // Postgres use an index it should not.
        $connection->executeStatement(\sprintf(<<<'SQL'
            INSERT INTO %s %s
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now(),
                now() + (gs || ' minutes')::interval
                %s
            FROM generate_series(1, 20000) gs
            SQL, $table, $columns, $extraColumns));

        $connection->executeStatement(\sprintf(<<<'SQL'
            INSERT INTO %s %s
            SELECT
                gen_random_uuid(),
                gen_random_uuid(),
                md5(gs::text || random()::text),
                now() - interval '90 days',
                now() - interval '89 days' - (gs || ' minutes')::interval
                %s
            FROM generate_series(1, 50) gs
            SQL, $table, $columns, $extraColumns));

        $connection->executeStatement(\sprintf('ANALYZE %s', $table));

        return $connection;
    }

    private function connection(): Connection
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @param list<string> $layers
     *
     * @return list<string>
     */
    private function phpFilesUnder(array $layers): array
    {
        $files = [];

        foreach ($layers as $layer) {
            $root = \dirname(__DIR__, 3).'/src/'.$layer;
            self::assertDirectoryExists($root);

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::assertNotEmpty($files, 'An empty file list would make every assertion over it vacuous.');

        return $files;
    }

    /**
     * Strips comments and docblocks, so a grep for SQL catches a *statement* rather than a paragraph
     * discussing one — and both these layers discuss SQL at length, deliberately.
     */
    private function sourceWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
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
