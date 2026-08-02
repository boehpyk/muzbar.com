<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Application\Identity\Handler\PruneExpiredChallengesHandler;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Port\Clock;
use App\Infrastructure\Identity\Console\PruneChallengesCommand;
use App\Infrastructure\Identity\Persistence\Doctrine\ChallengeIntegrityProbe;
use App\Tests\Fixture\RecordingLogger;
use App\Tests\Fixture\ThrowingEmailVerificationRequestRepository;
use App\Tests\Fixture\ThrowingRedisClient;
use App\Tests\Support\ClearsPruningState;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\ClientInterface as RedisClient;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `muzbar:identity:prune-challenges` end to end, through `CommandTester`, against the real
 * `muzbar_test` database.
 *
 * **`ClearsPruningState` is not optional here and the failure it prevents is silent.** DAMA rolls back
 * Postgres and does nothing for Redis, so the run lock and the heartbeat survive between tests. A
 * leftover lock makes the command do nothing, log `skipped: lock_held` and **exit 0** — so a test
 * asserting a successful run would be asserting against a run that never happened, and would pass.
 * That is why `make test` twice in a row is a gate of its own (AC-42) rather than a nicety.
 */
final class PruneChallengesCommandTest extends KernelTestCase
{
    use ClearsPruningState;

    private const string PRUNING_LOGGER_ID = 'monolog.logger.pruning';

    private CommandTester $command;
    private EmailVerificationRequestRepository $verificationRequests;
    private PasswordResetRequestRepository $resetRequests;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clearPruningState();

        $application = new Application($this->kernel());
        $this->command = new CommandTester($application->find('muzbar:identity:prune-challenges'));

        $verificationRequests = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $verificationRequests);
        $this->verificationRequests = $verificationRequests;

        $resetRequests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $resetRequests);
        $this->resetRequests = $resetRequests;
    }

    protected function tearDown(): void
    {
        $this->clearPruningState();

        parent::tearDown();
    }

    /**
     * AC-38: a clean run over a database with nothing overdue exits **0**.
     *
     * "Nothing to do" is a successful run, not a no-op to be reported as failure — the whole
     * observability design rests on a quiet run still being a run.
     */
    public function testACleanRunExitsZero(): void
    {
        self::assertSame(0, $this->command->execute([]));
    }

    /**
     * AC-20: **every** run emits exactly **one** INFO line carrying the full field set — including a
     * run that deleted nothing.
     *
     * The logger is swapped over the **concrete** service id `monolog.logger.pruning`, never an
     * alias: `ResolveReferencesToAliasesPass` rewrites alias references to their target at *compile*
     * time, so by the time this test runs the command's constructor is already wired to the concrete
     * id and setting an alias would change nothing while failing silently.
     *
     * The field list is asserted **exhaustively in both directions** — every expected key present, and
     * no unexpected key — because AC-20 names twelve fields and a line quietly missing one is a line
     * an operator will one day try to grep for and not find. Asserting only presence would let a
     * thirteenth field appear unnoticed; asserting only the count would not say which.
     */
    public function testEveryRunEmitsExactlyOneInfoLineWithTheFullFieldSet(): void
    {
        $logger = new RecordingLogger();
        self::getContainer()->set(self::PRUNING_LOGGER_ID, $logger);

        $application = new Application($this->kernel());
        $command = new CommandTester($application->find('muzbar:identity:prune-challenges'));

        self::assertSame(0, $command->execute([]));

        self::assertSame(1, $logger->count(), 'A quiet run must still log exactly one line — no more, and certainly no fewer.');

        $record = $logger->last();
        self::assertSame('info', $record['level']);
        self::assertSame('identity.challenge_pruning.completed', $record['message']);

        $expected = [
            'threshold_verification', 'threshold_reset',
            'overdue_verification', 'overdue_reset',
            'deleted_verification', 'deleted_reset',
            'orphaned_verification', 'orphaned_reset',
            'batches', 'truncated', 'dry_run', 'duration_ms',
        ];

        sort($expected);
        $actual = array_keys($record['context']);
        sort($actual);

        self::assertSame($expected, $actual, 'The run line must carry exactly AC-20\'s twelve fields.');
    }

    /**
     * AC-16: with the lock already held, the run deletes nothing, logs `skipped: lock_held` and
     * **exits 0**.
     *
     * Exit 0 because a skipped run is not a failed run — cron would otherwise mail the operator every
     * time the lock did precisely the job it exists to do.
     *
     * The overdue row is the assertion that matters: it proves the run genuinely did not happen
     * rather than happening and finding nothing.
     */
    public function testARunThatFindsTheLockHeldSkipsAndExitsZeroWithoutDeletingAnything(): void
    {
        $overdue = $this->persistOverdueVerification();
        $this->redis()->set(PruneChallengesCommand::LOCK_KEY, 'held-by-another-run');

        $logger = new RecordingLogger();
        self::getContainer()->set(self::PRUNING_LOGGER_ID, $logger);

        $application = new Application($this->kernel());
        $command = new CommandTester($application->find('muzbar:identity:prune-challenges'));

        self::assertSame(0, $command->execute([]));

        $record = $logger->last();
        self::assertSame('identity.challenge_pruning.skipped', $record['message']);
        self::assertSame('lock_held', $record['context']['skipped'] ?? null);

        self::assertTrue($this->rowExists('identity_email_verification_request', $overdue), 'A skipped run must delete nothing.');
        self::assertNull($this->redis()->get(PruneChallengesCommand::HEARTBEAT_KEY), 'A run that never happened must not claim it did.');
    }

    /**
     * AC-21: a completed run writes the heartbeat; `--dry-run` does **not**.
     *
     * The two halves are asserted in one test and against **each other** rather than in two tests
     * against two literals, because the claim is a *difference* between the two runs. Split apart,
     * one could start passing for the wrong reason while the other stayed green.
     *
     * A rehearsal that made the system look freshly swept would be lying about the one signal that
     * says the job is alive — which is exactly what an operator runs `--dry-run` to avoid.
     */
    public function testARealRunWritesTheHeartbeatAndADryRunDoesNot(): void
    {
        self::assertSame(0, $this->command->execute(['--dry-run' => true]));
        self::assertNull($this->redis()->get(PruneChallengesCommand::HEARTBEAT_KEY), 'A dry run must not write the heartbeat.');

        self::assertSame(0, $this->command->execute([]));
        $heartbeat = $this->redis()->get(PruneChallengesCommand::HEARTBEAT_KEY);

        self::assertIsString($heartbeat);
        self::assertInstanceOf(
            \DateTimeImmutable::class,
            \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $heartbeat) ?: null,
            'The heartbeat must be a parseable ISO-8601 instant, since /health/ready computes an age from it.',
        );
    }

    /**
     * AC-18 at the command boundary: `--dry-run` reports the backlog and deletes nothing.
     */
    public function testADryRunDeletesNothing(): void
    {
        $overdue = $this->persistOverdueVerification();

        self::assertSame(0, $this->command->execute(['--dry-run' => true]));

        self::assertTrue($this->rowExists('identity_email_verification_request', $overdue));
    }

    /**
     * A real run deletes the overdue row — the positive control without which every "nothing was
     * deleted" assertion above would also pass against a command that never deletes anything.
     */
    public function testARealRunDeletesOverdueRowsFromBothTables(): void
    {
        $overdueVerification = $this->persistOverdueVerification();
        $overdueReset = $this->persistOverdueReset();

        self::assertSame(0, $this->command->execute([]));

        self::assertFalse($this->rowExists('identity_email_verification_request', $overdueVerification));
        self::assertFalse($this->rowExists('identity_password_reset_request', $overdueReset));
    }

    /**
     * The `--limit` boundary is validated **before** the handler runs, and rejects everything an
     * `(int)` cast would silently reshape.
     *
     * Constitution §8: untrusted input crosses a validation boundary before reaching the Domain, and a
     * CLI argument is untrusted input even when only the operator can supply it. The overdue row is
     * the proof that nothing ran — a refusal that still deleted something would be worse than no
     * validation at all.
     */
    #[DataProvider('invalidLimits')]
    public function testAnInvalidLimitExitsOneBeforeAnythingIsDeleted(string $limit): void
    {
        $overdue = $this->persistOverdueVerification();

        self::assertSame(1, $this->command->execute(['--limit' => $limit]), \sprintf('--limit=%s must be refused.', $limit));
        self::assertTrue($this->rowExists('identity_email_verification_request', $overdue), 'A refused run must not have deleted anything.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['abc'];
        yield 'fractional' => ['1.5'];
        yield 'scientific notation' => ['1e3'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'empty' => [''];
    }

    /**
     * AC-19 at the command boundary: `--limit=N` caps deletions per table.
     */
    public function testAValidLimitCapsTheDeletionAndExitsZero(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->persistOverdueVerification();
        }

        self::assertSame(0, $this->command->execute(['--limit' => '2']));

        $surviving = array_filter($ids, fn (string $id): bool => $this->rowExists('identity_email_verification_request', $id));

        self::assertCount(1, $surviving, 'Exactly one of the three overdue rows must survive a --limit=2 run.');
    }

    /**
     * AC-13 through the command: a second run immediately after the first deletes nothing, exits 0,
     * and still emits its line.
     */
    public function testASecondRunDeletesNothingAndStillReportsItself(): void
    {
        $this->persistOverdueVerification();

        // The logger is swapped BEFORE the first run, not between the two. Symfony's test container
        // refuses to replace a service that has already been initialized, and the first run
        // initializes it — so a swap in the middle throws rather than quietly missing the second
        // run's records. Both runs therefore land in one double, and the assertions below are about
        // the second record rather than about a fresh logger's only one.
        $logger = new RecordingLogger();
        self::getContainer()->set(self::PRUNING_LOGGER_ID, $logger);

        $application = new Application($this->kernel());
        $command = new CommandTester($application->find('muzbar:identity:prune-challenges'));

        self::assertSame(0, $command->execute([]));
        self::assertSame(1, $logger->last()['context']['deleted_verification'] ?? null, 'The first run clears the backlog.');

        self::assertSame(0, $command->execute([]));

        self::assertSame(2, $logger->count(), 'A run with nothing to do still logs — that is the whole point.');
        self::assertSame(0, $logger->last()['context']['deleted_verification'], 'The second run finds nothing: idempotent by construction.');
        self::assertFalse($logger->last()['context']['truncated']);
    }

    /**
     * AC-16's **fail-open** half: with Redis unreachable for the lock's own `set()`, the run does not
     * stop — it proceeds, sweeps as normal, and logs the failure at **warning** rather than error.
     *
     * THIS IS THE HALF OF AC-16 THE OTHER LOCK TEST CANNOT REACH. `testARunThatFindsTheLockHeldSkipsAndExitsZeroWithoutDeletingAnything`
     * covers "another run holds the lock" — `acquireRunLock()` returning `false`, which is a
     * **successful** Redis call that says no. This test covers the third outcome the port's own
     * docblock names explicitly: Redis could not be **asked** at all, `acquireRunLock()` catches and
     * returns `null`, and the run must not confuse "nobody may run" with "nobody could ask". A
     * well-meaning "hardening" change that made the lock block on a Redis failure instead of proceeding
     * would silently couple housekeeping's correctness to a cache being up — exactly the coupling the
     * command's own docblock refuses at length. The overdue row proves the run genuinely swept rather
     * than merely not-skipping; `identity.challenge_pruning.completed` still appearing proves it did
     * not stop partway through.
     *
     * BUILT VIA `commandWith()`, NOT VIA `self::getContainer()->set(Predis\Client::class, ...)`. This
     * file's own `setUp()` already calls `ClearsPruningState::clearPruningState()`, which fetches
     * `Predis\ClientInterface` from the container and so — because the alias resolves to the
     * **concrete** id `Predis\Client` (CLAUDE.md: "target the concrete class's service id, never the
     * port alias") — marks that concrete service as already initialized before this test method ever
     * runs. Symfony's `TestContainer` refuses to `set()` an already-initialized service
     * (`InvalidArgumentException: ... already initialized, you cannot replace it`), so the swap this
     * test needs is structurally impossible through the container. `commandWith()` sidesteps the
     * container for the one collaborator under test by constructing `PruneChallengesCommand` directly
     * — the same technique the AC-38 test below needs for the same reason, one collaborator over.
     * `ThrowingRedisClient` fails only `set(LOCK_KEY, ...)`, so the heartbeat `set()` a few lines later
     * in the same run still succeeds.
     */
    public function testWithRedisUnavailableForTheLockTheRunProceedsAndLogsAWarning(): void
    {
        $overdue = $this->persistOverdueVerification();

        $logger = new RecordingLogger();
        $command = $this->commandWith(redis: new ThrowingRedisClient(PruneChallengesCommand::LOCK_KEY), logger: $logger);

        self::assertSame(0, $command->execute([]), 'A Redis outage on the lock must not fail the run.');

        $lockUnavailable = $logger->find('identity.challenge_pruning.lock_unavailable');
        self::assertNotNull($lockUnavailable, 'The fail-open path must log that it could not ask for the lock.');
        self::assertSame('warning', $lockUnavailable['level']);
        self::assertSame(PruneChallengesCommand::LOCK_KEY, $lockUnavailable['context']['key'] ?? null);

        self::assertNotNull(
            $logger->find('identity.challenge_pruning.completed'),
            'The run must still complete and emit AC-20\'s line — the lock is politeness, not correctness.',
        );

        self::assertFalse(
            $this->rowExists('identity_email_verification_request', $overdue),
            'A run that proceeded without the lock must still have swept the overdue row.',
        );
    }

    /**
     * AC-27: a non-zero orphan count is **additionally** logged at warning, alongside — not instead
     * of — AC-20's own completion line.
     *
     * The orphan is built by issuing a request against a `UserId` that was never given a `User` row,
     * exactly as `ChallengeIntegrityProbe`'s anti-join defines "orphaned". It is issued **fresh**
     * (`issuedAt = now`) rather than overdue, because the probe runs *after* the sweep
     * (`PruneChallengesCommand::execute()`): a row old enough to be swept would already be gone by the
     * time the probe counted it, and the assertion would test nothing.
     *
     * `warning` rather than `error`, and both lines present rather than one replacing the other, are
     * both asserted: the run is not broken by an orphan (ADR-0012 decision 6 — orphan-ness is a thing
     * to report, not a thing to act on), so it still emits its ordinary completion line, and this
     * extra line is what makes the non-zero reading visible to something that greps for `warning`
     * without reading every `completed` line's payload.
     */
    public function testANonZeroOrphanCountIsAdditionallyLoggedAtWarning(): void
    {
        $this->persistFreshVerification();
        $this->persistFreshReset();

        $logger = new RecordingLogger();
        $command = $this->commandWith(logger: $logger);

        self::assertSame(0, $command->execute([]));

        self::assertSame(2, $logger->count(), 'A non-zero orphan count adds a second line; it does not replace the first.');

        $orphansPresent = $logger->find('identity.challenge_pruning.orphans_present');
        self::assertNotNull($orphansPresent);
        self::assertSame('warning', $orphansPresent['level']);
        self::assertSame(1, $orphansPresent['context']['orphaned_verification'] ?? null);
        self::assertSame(1, $orphansPresent['context']['orphaned_reset'] ?? null);

        $completed = $logger->find('identity.challenge_pruning.completed');
        self::assertNotNull($completed);
        self::assertSame('info', $completed['level']);
        self::assertSame(1, $completed['context']['orphaned_verification'] ?? null);
        self::assertSame(1, $completed['context']['orphaned_reset'] ?? null);
    }

    /**
     * The heartbeat-write-failure path: a Redis outage on the **heartbeat's** `set()` is logged at
     * warning and does not fail an otherwise-successful run.
     *
     * Contrasted deliberately with the lock-unavailable test above rather than merged with it, because
     * `ThrowingRedisClient` fails exactly one key and the two failures must be shown **not** to
     * compound — a double that failed every `set()` call could not tell "the lock failed" apart from
     * "the heartbeat failed", and this slice's whole observability design rests on those being
     * different facts (`writeHeartbeat()`'s own docblock: the heartbeat degrading is "exactly what a
     * secondary signal is allowed to do", while the backlog count — unaffected here — remains AC-24's
     * primary signal). Built via `commandWith()` for the same reason the lock test above needs it: the
     * real Redis client is already initialized by `setUp()`'s `clearPruningState()` before this test
     * body runs, so the container cannot `set()` a replacement for it.
     */
    public function testAHeartbeatWriteFailureIsLoggedAtWarningAndDoesNotFailTheRun(): void
    {
        $logger = new RecordingLogger();
        $command = $this->commandWith(redis: new ThrowingRedisClient(PruneChallengesCommand::HEARTBEAT_KEY), logger: $logger);

        self::assertSame(0, $command->execute([]), 'A heartbeat write failure must not fail an otherwise-successful run.');

        $heartbeatFailed = $logger->find('identity.challenge_pruning.heartbeat_failed');
        self::assertNotNull($heartbeatFailed, 'A heartbeat write failure must be logged.');
        self::assertSame('warning', $heartbeatFailed['level']);
        self::assertSame(PruneChallengesCommand::HEARTBEAT_KEY, $heartbeatFailed['context']['key'] ?? null);

        self::assertNotNull(
            $logger->find('identity.challenge_pruning.completed'),
            'The sweep itself succeeded; the run\'s own report must say so regardless of the heartbeat.',
        );
    }

    /**
     * AC-38, the failure contract: Postgres unreachable means the run logs at **error**, deletes
     * nothing, and exits **1** — the one path in this file where the command is genuinely allowed to
     * fail.
     *
     * `PruneExpiredChallengesHandler` is built here **directly**, wired to a double that throws on
     * every call in place of the real `DoctrineEmailVerificationRequestRepository` adapter, so
     * `sweep()` fails on its very first port call — before a single row is touched anywhere and before
     * the reset table's sweep is even reached. The overdue row therefore proves two things at once:
     * that nothing was deleted, and that the failure is real rather than the row simply never having
     * qualified.
     *
     * NOT A CONTAINER SWAP, AND THE REASON IS THE SAME SHAPE AS THE TWO REDIS TESTS ABOVE.
     * `self::getContainer()->set(DoctrineEmailVerificationRequestRepository::class, ...)` looks like
     * the obvious move — it is the concrete adapter id, not the port alias — but this file's own
     * `setUp()` already resolves it via `self::getContainer()->get(EmailVerificationRequestRepository::class)`
     * into `$this->verificationRequests`, so by the time this test body runs the concrete service is
     * already initialized and `TestContainer` refuses to replace it. Constructing the handler by hand,
     * against the container's *real* `PasswordResetRequestRepository` and `Clock` (fetched via `get()`,
     * which is always safe regardless of initialization) plus the throwing double, reaches the same
     * failure without needing the container to allow a swap it structurally cannot.
     *
     * `logger->count() === 1` matters as much as the error line's content: `PruneExpiredChallengesHandler::__invoke()`'s
     * own docblock is explicit that a failed run "emits no AC-20 line... because that line reports what
     * a run measured, and this run measured nothing" — so a second, `completed` line here would be the
     * regression this test exists to catch.
     */
    public function testWithPostgresUnreachableTheRunLogsAtErrorDeletesNothingAndExitsOne(): void
    {
        $overdue = $this->persistOverdueVerification();

        $resetRequests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $resetRequests);

        $clock = self::getContainer()->get(Clock::class);
        self::assertInstanceOf(Clock::class, $clock);

        $handler = new PruneExpiredChallengesHandler(
            new ThrowingEmailVerificationRequestRepository(),
            $resetRequests,
            $clock,
        );

        $logger = new RecordingLogger();
        $command = $this->commandWith(handler: $handler, logger: $logger);

        self::assertSame(1, $command->execute([]), 'A genuine infrastructure failure must exit non-zero.');

        self::assertSame(1, $logger->count(), 'A failed run measured nothing and must not also emit AC-20\'s completion line.');

        $record = $logger->last();
        self::assertSame('error', $record['level']);
        self::assertSame('identity.challenge_pruning.failed', $record['message']);
        self::assertArrayHasKey('exception', $record['context']);
        self::assertFalse($record['context']['dry_run'] ?? null);

        self::assertTrue(
            $this->rowExists('identity_email_verification_request', $overdue),
            'A failed run must not have deleted anything.',
        );
        self::assertNull(
            $this->redis()->get(PruneChallengesCommand::HEARTBEAT_KEY),
            'A failed run must not claim to have completed by writing the heartbeat.',
        );
    }

    private function persistOverdueVerification(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->verificationRequests->nextIdentity();

        $request = EmailVerificationRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),
            $now->modify(\sprintf(
                '-%d seconds',
                EmailVerificationRequest::LIFETIME_SECONDS + EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS + 86400,
            )),
        );
        $request->releaseEvents();
        $this->verificationRequests->save($request);

        return $id->toString();
    }

    private function persistOverdueReset(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->resetRequests->nextIdentity();

        $request = PasswordResetRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedResetToken::fromString(hash('sha256', random_bytes(32))),
            $now->modify(\sprintf(
                '-%d seconds',
                PasswordResetRequest::LIFETIME_SECONDS + PasswordResetRequest::RETENTION_AFTER_EXPIRY_SECONDS + 86400,
            )),
        );
        $request->releaseEvents();
        $this->resetRequests->save($request);

        return $id->toString();
    }

    /**
     * A verification request issued **now**, against a `UserId` that names no `User` row.
     *
     * "Fresh" rather than overdue on purpose, for `testANonZeroOrphanCountIsAdditionallyLoggedAtWarning`'s
     * reason: `ChallengeIntegrityProbe` runs *after* the sweep, so a row old enough to be swept would
     * already be gone by the time it was counted, and the orphan assertion would test nothing. The
     * random `UserId` is what makes the row an orphan — every other test in this file uses the same
     * random id and never notices, because nothing before this test ever asked whether the user
     * behind it exists.
     */
    private function persistFreshVerification(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->verificationRequests->nextIdentity();

        $request = EmailVerificationRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),
            $now,
        );
        $request->releaseEvents();
        $this->verificationRequests->save($request);

        return $id->toString();
    }

    /**
     * The reset-table twin of `persistFreshVerification()` — see its docblock for why "fresh" is the
     * requirement here rather than "overdue".
     */
    private function persistFreshReset(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $id = $this->resetRequests->nextIdentity();

        $request = PasswordResetRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedResetToken::fromString(hash('sha256', random_bytes(32))),
            $now,
        );
        $request->releaseEvents();
        $this->resetRequests->save($request);

        return $id->toString();
    }

    /**
     * Read straight from Postgres: the sweep deletes through native SQL and never notifies Doctrine's
     * identity map, so an ORM read could hand back a cached object for a row that is gone.
     */
    private function rowExists(string $table, string $id): bool
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        /** @var int|numeric-string $count */
        $count = $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table), ['id' => $id]);

        return (bool) (int) $count;
    }

    private function redis(): RedisClient
    {
        $redis = self::getContainer()->get(RedisClient::class);
        self::assertInstanceOf(RedisClient::class, $redis);

        return $redis;
    }

    /**
     * Builds a `PruneChallengesCommand` **directly**, rather than through
     * `$application->find('muzbar:identity:prune-challenges')`, for the tests that fake exactly one
     * collaborator.
     *
     * WHY THIS EXISTS AT ALL. Every other test in this file swaps a collaborator by calling
     * `self::getContainer()->set(<concrete id>, $double)` before asking the container-built
     * `Application` for the command — the same technique `testEveryRunEmitsExactlyOneInfoLineWithTheFullFieldSet`
     * uses for the logger. That technique stops working the moment `setUp()` (or `ClearsPruningState`,
     * which `setUp()` calls) has already resolved the concrete service the test wants to fake:
     * Symfony's `TestContainer` refuses to `set()` an already-initialized service, and this class's own
     * `setUp()` resolves both `Predis\ClientInterface` (via `clearPruningState()`) and
     * `EmailVerificationRequestRepository` (into `$this->verificationRequests`) before any test body
     * runs. Building the command by hand sidesteps the container for the one collaborator under test
     * while still asking the container — via `get()`, which is always safe regardless of
     * initialization — for every collaborator the test does not care about.
     *
     * Each parameter defaults to the real, container-resolved collaborator, so a caller states only
     * what it is faking.
     */
    private function commandWith(
        ?PruneExpiredChallengesHandler $handler = null,
        ?RedisClient $redis = null,
        ?RecordingLogger $logger = null,
    ): CommandTester {
        if (!$handler instanceof PruneExpiredChallengesHandler) {
            $handler = self::getContainer()->get(PruneExpiredChallengesHandler::class);
            self::assertInstanceOf(PruneExpiredChallengesHandler::class, $handler);
        }

        $probe = self::getContainer()->get(ChallengeIntegrityProbe::class);
        self::assertInstanceOf(ChallengeIntegrityProbe::class, $probe);

        $clock = self::getContainer()->get(Clock::class);
        self::assertInstanceOf(Clock::class, $clock);

        return new CommandTester(new PruneChallengesCommand(
            $handler,
            $probe,
            $redis ?? $this->redis(),
            $clock,
            $logger ?? new RecordingLogger(),
        ));
    }

    private function kernel(): KernelInterface
    {
        if (!self::$kernel instanceof KernelInterface) {
            self::fail('Expected the kernel to be booted.');
        }

        return self::$kernel;
    }
}
