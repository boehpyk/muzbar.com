<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Identity\Console\PruneChallengesCommand;
use App\Tests\Fixture\RecordingLogger;
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

    private function kernel(): KernelInterface
    {
        if (!self::$kernel instanceof KernelInterface) {
            self::fail('Expected the kernel to be booted.');
        }

        return self::$kernel;
    }
}
