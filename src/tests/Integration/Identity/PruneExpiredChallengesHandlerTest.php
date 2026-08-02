<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\PruneExpiredChallenges;
use App\Application\Identity\Handler\PruneExpiredChallengesHandler;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Tests\Fixture\CountingResetRequestRepository;
use App\Tests\Fixture\CountingVerificationRequestRepository;
use App\Tests\Fixture\FrozenClock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `PruneExpiredChallengesHandler` — the use case, driven with a `FrozenClock`.
 *
 * WHICH TESTS USE THE REAL ADAPTERS AND WHICH USE FAKES, STATED UP FRONT BECAUSE IT IS A REAL
 * TRADE-OFF RATHER THAN A CONVENIENCE. Anything asserting what actually happens to rows — the pinned
 * thresholds, the dry run, the empty database — runs against the real Doctrine adapters and the real
 * `muzbar_test` database, because those claims are about SQL and a fake would only prove the fake
 * agrees with itself. The **cap** tests use in-memory fakes, because driving
 * `MAX_BATCHES_PER_TABLE` honestly means 50 batches of 1000 rows — fifty thousand inserts per table,
 * per test. The handler is a pure function of its three ports precisely so that this substitution is
 * available, and the substitution is the payoff for having kept Redis, the probe and the console out
 * of it.
 *
 * WHAT IS **NOT** COVERED HERE, SAID PLAINLY RATHER THAN IMPLIED. `MAX_RUN_SECONDS` — the wall-clock
 * cap — **is not tested at all**, and cannot be with the tools this suite allows. The only clock a
 * test may use is a frozen one, and a frozen clock never reaches a deadline: the loop's
 * `$this->clock->now() >= $runDeadline` branch is unreachable by construction. Advancing time would
 * mean `sleep()`, which buys a slow flaky test rather than a fast honest one. That gap is exactly why
 * `MAX_BATCHES_PER_TABLE` exists as a second, clock-independent cap — a cap nothing can exercise is a
 * cap nobody should rely on alone — and it is the one the tests below drive. **This paragraph is the
 * point:** a docblock claiming coverage its assertions cannot deliver is the same defect as a test
 * that ratifies an accident, one level up.
 */
final class PruneExpiredChallengesHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-08-01T12:00:00+00:00';

    private EmailVerificationRequestRepository $verificationRequests;
    private PasswordResetRequestRepository $resetRequests;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $verificationRequests = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $verificationRequests);
        $this->verificationRequests = $verificationRequests;

        $resetRequests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $resetRequests);
        $this->resetRequests = $resetRequests;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * AC-10: **one** `Clock::now()` pins **both** thresholds.
     *
     * Asserted by reading both `SweepOutcome::$thresholdAt` values off the report and checking each
     * against the frozen instant minus that aggregate's own literal window — 604 800 and 2 592 000,
     * from the feature spec rather than from the constants. If the handler took a second reading for
     * the second table, the two thresholds would be derived from two instants; with a frozen clock
     * they would still agree, which is exactly why the assertion is written as *"each threshold is
     * exactly its own window before the one instant"* rather than *"the two thresholds are
     * consistent"*. The latter would pass under the bug.
     *
     * Real adapters: this is a claim about what the handler hands the ports.
     */
    public function testOneClockReadingPinsBothThresholds(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $report = $this->handlerWithRealAdapters($now)(new PruneExpiredChallenges(null, false));

        self::assertSame(
            '2026-07-25T12:00:00+00:00',
            $report->verification->thresholdAt->format(\DateTimeInterface::ATOM),
            'The verification threshold must be exactly 604800 s before the run instant.',
        );
        self::assertSame(
            '2026-07-02T12:00:00+00:00',
            $report->reset->thresholdAt->format(\DateTimeInterface::ATOM),
            'The reset threshold must be exactly 2592000 s before the SAME run instant.',
        );
        self::assertSame(604800, $now->getTimestamp() - $report->verification->thresholdAt->getTimestamp());
        self::assertSame(2592000, $now->getTimestamp() - $report->reset->thresholdAt->getTimestamp());
    }

    /**
     * AC-13: a run over an empty database returns an all-zero report and does not throw — and a
     * second run immediately after the first deletes nothing.
     *
     * Idempotence here is not a mechanism anyone built; it falls out of a `DELETE` matching a
     * predicate. That is the property the console command's run lock is explicitly allowed to be
     * merely polite about, so it is worth an assertion of its own.
     */
    public function testARunOverAnEmptyDatabaseReportsAllZerosAndASecondRunDeletesNothing(): void
    {
        $handler = $this->handlerWithRealAdapters(new \DateTimeImmutable(self::NOW));

        $first = $handler(new PruneExpiredChallenges(null, false));

        self::assertSame(0, $first->verification->overdueBefore);
        self::assertSame(0, $first->verification->deleted);
        self::assertSame(0, $first->reset->overdueBefore);
        self::assertSame(0, $first->reset->deleted);
        self::assertFalse($first->verification->truncated);
        self::assertFalse($first->reset->truncated);
        self::assertFalse($first->dryRun);

        $second = $handler(new PruneExpiredChallenges(null, false));

        self::assertSame(0, $second->verification->deleted);
        self::assertSame(0, $second->reset->deleted);
    }

    /**
     * AC-18: `dryRun` reports the same backlog and deletes **zero** rows, asserted by raw row counts
     * before and after.
     *
     * The counts are read straight from Postgres rather than from the report, because a report is the
     * job's own account of itself and the whole question here is whether that account is true.
     */
    public function testADryRunReportsTheBacklogAndDeletesNothing(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $this->persistOverdueVerification($now, 3);
        $this->persistOverdueReset($now, 2);

        $verificationBefore = $this->tableCount('identity_email_verification_request');
        $resetBefore = $this->tableCount('identity_password_reset_request');

        $report = $this->handlerWithRealAdapters($now)(new PruneExpiredChallenges(null, true));

        self::assertTrue($report->dryRun);
        self::assertSame(3, $report->verification->overdueBefore);
        self::assertSame(2, $report->reset->overdueBefore);
        self::assertSame(0, $report->verification->deleted);
        self::assertSame(0, $report->reset->deleted);
        self::assertSame(0, $report->verification->batches, 'A dry run must not run a single batch.');
        self::assertSame(0, $report->reset->batches);

        self::assertSame($verificationBefore, $this->tableCount('identity_email_verification_request'));
        self::assertSame($resetBefore, $this->tableCount('identity_password_reset_request'));
    }

    /**
     * A real run deletes exactly the overdue rows and leaves everything else, with the report's
     * numbers asserted against the **observed** row delta rather than against themselves.
     */
    public function testARealRunDeletesTheOverdueRowsAndLeavesTheRest(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $this->persistOverdueVerification($now, 3);
        $this->persistOverdueReset($now, 2);
        $liveVerification = $this->persistVerificationExpiringAt($now->modify('+1 hour'));
        $liveReset = $this->persistResetExpiringAt($now->modify('+30 minutes'));

        $verificationBefore = $this->tableCount('identity_email_verification_request');
        $resetBefore = $this->tableCount('identity_password_reset_request');

        $report = $this->handlerWithRealAdapters($now)(new PruneExpiredChallenges(null, false));

        self::assertSame(
            $verificationBefore - $this->tableCount('identity_email_verification_request'),
            $report->verification->deleted,
        );
        self::assertSame(
            $resetBefore - $this->tableCount('identity_password_reset_request'),
            $report->reset->deleted,
        );
        self::assertSame(3, $report->verification->deleted);
        self::assertSame(2, $report->reset->deleted);
        self::assertTrue($this->rowExists('identity_email_verification_request', $liveVerification));
        self::assertTrue($this->rowExists('identity_password_reset_request', $liveReset));
    }

    /**
     * The loop stops on a **short** batch rather than paying for one extra round trip to see a zero.
     *
     * Driven with counting fakes, which is the only way to observe how many calls the handler made —
     * the real adapters would give the right row counts and say nothing about the call pattern. With
     * 2500 overdue rows and a batch size of 1000 the batches come back 1000, 1000, 500; the third is
     * short, so the table is drained and there must be no fourth call.
     */
    public function testTheLoopTerminatesOnAShortBatchWithoutAnExtraRoundTrip(): void
    {
        $verification = new CountingVerificationRequestRepository(2500);
        $reset = new CountingResetRequestRepository(0);

        $report = $this->handlerWithFakes($verification, $reset)(new PruneExpiredChallenges(null, false));

        self::assertSame(3, $verification->deleteCalls, 'Three batches: 1000, 1000, 500 — and no fourth call to learn what the short batch already said.');
        self::assertSame(2500, $report->verification->deleted);
        self::assertSame(3, $report->verification->batches);
        self::assertFalse($report->verification->truncated, 'A drained table is not a truncated run.');

        self::assertSame(1, $reset->deleteCalls, 'An empty table still costs exactly one call, which returns 0 — a short batch.');
        self::assertSame(0, $report->reset->deleted);
    }

    /**
     * AC-14: hitting `MAX_BATCHES_PER_TABLE` reports `truncated: true`, and a **second invocation
     * drains the remainder** with nothing deleted twice and nothing skipped.
     *
     * Fakes, for the reason in the class docblock: an honest version of this test against the real
     * database is 50 000 inserts per table. The fake counts rows the same way the table would and
     * cannot delete the same row twice, so "nothing twice, nothing skipped" is asserted as the total
     * across both runs exactly equalling the starting population.
     *
     * This is the resumability that makes AC-15 (interruption safety) an argument rather than a test
     * that kills a process — batches commit independently, so a run that stops early is
     * indistinguishable, to the next run, from one that was never going to finish.
     */
    public function testHittingTheBatchCapTruncatesAndASecondRunDrainsTheRemainder(): void
    {
        $population = PruneExpiredChallengesHandler::BATCH_SIZE * PruneExpiredChallengesHandler::MAX_BATCHES_PER_TABLE + 1500;

        $verification = new CountingVerificationRequestRepository($population);
        $reset = new CountingResetRequestRepository(0);
        $handler = $this->handlerWithFakes($verification, $reset);

        $first = $handler(new PruneExpiredChallenges(null, false));

        self::assertTrue($first->verification->truncated, 'A run stopped by the batch cap must say so.');
        self::assertSame(PruneExpiredChallengesHandler::MAX_BATCHES_PER_TABLE, $first->verification->batches);
        self::assertSame(50000, $first->verification->deleted);
        self::assertSame($population, $first->verification->overdueBefore, 'The backlog is measured before the sweep, so it reports the whole population.');

        $second = $handler(new PruneExpiredChallenges(null, false));

        self::assertFalse($second->verification->truncated, 'The second run drains what is left and is not truncated.');
        self::assertSame(1500, $second->verification->deleted);
        self::assertSame(
            $population,
            $first->verification->deleted + $second->verification->deleted,
            'Every row deleted exactly once: nothing twice, nothing skipped.',
        );

        $third = $handler(new PruneExpiredChallenges(null, false));

        self::assertSame(0, $third->verification->deleted);
        self::assertSame(0, $third->verification->overdueBefore);
    }

    /**
     * AC-19 at the use-case level: `--limit` caps deletions **per table**, and the cap applies to
     * each table independently rather than to the run as a whole.
     */
    public function testTheLimitCapsDeletionsPerTableIndependently(): void
    {
        $verification = new CountingVerificationRequestRepository(10);
        $reset = new CountingResetRequestRepository(10);

        $report = $this->handlerWithFakes($verification, $reset)(new PruneExpiredChallenges(4, false));

        self::assertSame(4, $report->verification->deleted);
        self::assertSame(4, $report->reset->deleted, 'The limit is per table, not a budget shared across the run.');
        self::assertSame(4, $verification->largestLimitRequested, 'The port must never be asked for more than the cap allows.');
    }

    private function handlerWithRealAdapters(\DateTimeImmutable $now): PruneExpiredChallengesHandler
    {
        return new PruneExpiredChallengesHandler(
            $this->verificationRequests,
            $this->resetRequests,
            new FrozenClock($now),
        );
    }

    private function handlerWithFakes(
        CountingVerificationRequestRepository $verification,
        CountingResetRequestRepository $reset,
    ): PruneExpiredChallengesHandler {
        return new PruneExpiredChallengesHandler(
            $verification,
            $reset,
            new FrozenClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    private function persistOverdueVerification(\DateTimeImmutable $now, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->persistVerificationExpiringAt(
                EmailVerificationRequest::retentionThreshold($now)->modify(\sprintf('-%d hours', $i)),
            );
        }
    }

    private function persistOverdueReset(\DateTimeImmutable $now, int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->persistResetExpiringAt(
                PasswordResetRequest::retentionThreshold($now)->modify(\sprintf('-%d hours', $i)),
            );
        }
    }

    private function persistVerificationExpiringAt(\DateTimeImmutable $expiresAt): string
    {
        $id = $this->verificationRequests->nextIdentity();
        $request = EmailVerificationRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),
            $expiresAt->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS)),
        );
        $request->releaseEvents();
        $this->verificationRequests->save($request);

        return $id->toString();
    }

    private function persistResetExpiringAt(\DateTimeImmutable $expiresAt): string
    {
        $id = $this->resetRequests->nextIdentity();
        $request = PasswordResetRequest::issue(
            $id,
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedResetToken::fromString(hash('sha256', random_bytes(32))),
            $expiresAt->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS)),
        );
        $request->releaseEvents();
        $this->resetRequests->save($request);

        return $id->toString();
    }

    private function tableCount(string $table): int
    {
        /** @var int|numeric-string $count */
        $count = $this->entityManager->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));

        return (int) $count;
    }

    private function rowExists(string $table, string $id): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table),
            ['id' => $id],
        );
    }
}
