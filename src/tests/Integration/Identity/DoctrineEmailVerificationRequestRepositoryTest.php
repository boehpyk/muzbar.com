<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `DoctrineEmailVerificationRequestRepository` against the real `muzbar_test`
 * database (DAMA wraps each test in a transaction that rolls back — no mocked database, per
 * CLAUDE.md).
 */
final class DoctrineEmailVerificationRequestRepositoryTest extends KernelTestCase
{
    private EmailVerificationRequestRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $repository);
        $this->repository = $repository;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * The technical plan's "this is the test that catches a broken DBAL type": every value object
     * must round-trip through Postgres and back as an *equal* value, not merely a non-null one.
     * Clearing the identity map before `findByTokenHash()` forces a real hydration from the database
     * row rather than returning the same in-memory object.
     */
    public function testSaveThenFindByTokenHashRoundTripsEveryValueObjectWithEqualValues(): void
    {
        $id = $this->repository->nextIdentity();
        $userId = $this->aUserId();
        $hash = HashedVerificationToken::fromString(hash('sha256', random_bytes(32)));
        $issuedAt = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $request = EmailVerificationRequest::issue($id, $userId, $hash, $issuedAt);
        $this->repository->save($request);

        $this->entityManager->clear();

        $found = $this->repository->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertNotSame($request, $found, 'clear() must force a fresh hydration, not return the same object');
        self::assertTrue($found->id()->equals($id));
        self::assertTrue($found->userId()->equals($userId));
        self::assertTrue($found->tokenHash()->equals($hash));
        self::assertEquals($issuedAt, $found->issuedAt());
        self::assertEquals($request->expiresAt(), $found->expiresAt());
        self::assertNull($found->redeemedAt());
        self::assertFalse($found->isRedeemed());
    }

    /**
     * The nullable `redeemed_at` round-trips too, once set — the same "not merely not-null, but the
     * same instant" guarantee `DoctrineUserRepositoryTest` proves for `emailVerifiedAt` (gotcha:
     * compare via `DateTimeImmutable` equality, never the timezone name — Postgres reads back
     * `+00:00`, not the literal `UTC`).
     */
    public function testSaveThenFindByTokenHashRoundTripsARedeemedRequest(): void
    {
        $hash = HashedVerificationToken::fromString(hash('sha256', random_bytes(32)));
        $request = EmailVerificationRequest::issue(
            $this->repository->nextIdentity(),
            $this->aUserId(),
            $hash,
            new \DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );
        $redeemedAt = new \DateTimeImmutable('2026-07-26T11:00:00+00:00');
        $request->redeem($hash, $redeemedAt);
        $this->repository->save($request);

        $this->entityManager->clear();

        $found = $this->repository->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->isRedeemed());
        self::assertEquals($redeemedAt, $found->redeemedAt());
    }

    public function testFindByTokenHashReturnsNullForAHashThatWasNeverSaved(): void
    {
        $neverSaved = HashedVerificationToken::fromString(hash('sha256', random_bytes(32)));

        self::assertNull($this->repository->findByTokenHash($neverSaved));
    }

    /**
     * `countIssuedForUserSince` boundary behaviour: a request issued exactly *at* `$since` counts
     * (the DQL uses `>=`), one issued a second before it does not, and a request belonging to a
     * different user is never counted at all.
     */
    public function testCountIssuedForUserSinceIncludesTheBoundaryInstantAndExcludesOneSecondBeforeIt(): void
    {
        $userId = $this->aUserId();
        $since = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        $atBoundary = EmailVerificationRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since);
        $this->repository->save($atBoundary);

        $beforeBoundary = EmailVerificationRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since->modify('-1 second'));
        $this->repository->save($beforeBoundary);

        $otherUsersRequest = EmailVerificationRequest::issue($this->repository->nextIdentity(), $this->aUserId(), $this->aHash(), $since);
        $this->repository->save($otherUsersRequest);

        self::assertSame(1, $this->repository->countIssuedForUserSince($userId, $since));
    }

    public function testCountIssuedForUserSinceCountsEveryMatchingRequestNotJustOne(): void
    {
        $userId = $this->aUserId();
        $since = new \DateTimeImmutable('2026-07-26T10:00:00+00:00');

        foreach (range(1, 3) as $i) {
            $request = EmailVerificationRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since->modify(\sprintf('+%d minute', $i)));
            $this->repository->save($request);
        }

        self::assertSame(3, $this->repository->countIssuedForUserSince($userId, $since));
    }

    public function testCountIssuedForUserSinceIsZeroForAUserWithNoRequests(): void
    {
        self::assertSame(0, $this->repository->countIssuedForUserSince($this->aUserId(), new \DateTimeImmutable()));
    }

    public function testNextIdentityReturnsDistinctIds(): void
    {
        $ids = array_map(
            fn (): EmailVerificationRequestId => $this->repository->nextIdentity(),
            range(1, 5),
        );

        $unique = array_unique(array_map(static fn (EmailVerificationRequestId $id): string => $id->toString(), $ids));

        self::assertCount(5, $unique);
    }

    /**
     * AC-4, **both sides of the boundary**, which is the only way to learn which operator is in the
     * code. A row at `threshold − 1 s` is deleted; rows at exactly `threshold` and at
     * `threshold + 1 s` are kept.
     *
     * Asserting only the deleted side would pass with `<`, `<=` **and** with a predicate that
     * deleted everything. Asserting only the kept side would pass with a method that deleted
     * nothing at all. At whole-second storage the difference between `<` and `<=` is one row, and
     * one row is exactly what a one-sided test cannot see.
     *
     * Survivors are counted with raw SQL rather than through the repository, and that is not
     * fastidiousness: `deleteExpiredBefore()` executes native SQL through the DBAL connection and
     * **never tells Doctrine's identity map the rows are gone**, so an ORM read taken after the sweep
     * can hand back an object for a row that no longer exists.
     */
    public function testDeleteExpiredBeforeIsStrictOnBothSidesOfTheThreshold(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        $justOverdue = $this->persistExpiringAt($threshold->modify('-1 second'));
        $exactlyOnTheThreshold = $this->persistExpiringAt($threshold);
        $oneSecondSafe = $this->persistExpiringAt($threshold->modify('+1 second'));

        $deleted = $this->repository->deleteExpiredBefore($threshold, 100);

        self::assertSame(1, $deleted, 'Only the row strictly before the threshold may be deleted.');
        self::assertFalse($this->rowExists($justOverdue), 'threshold - 1s must be deleted.');
        self::assertTrue($this->rowExists($exactlyOnTheThreshold), 'A row exactly at the threshold must be KEPT — the comparison is strict.');
        self::assertTrue($this->rowExists($oneSecondSafe), 'threshold + 1s must be kept.');
    }

    /**
     * `countExpiredBefore()` uses the same strict `<` as the delete, and the two are asserted
     * **against each other** rather than against two copies of a literal.
     *
     * If the count used `<=` while the sweep used `<`, a row sitting exactly on the threshold would
     * be counted forever and removed never — a healthy table reporting a permanent backlog of one,
     * which is an alarm nobody can clear and everybody learns to ignore. Two separate literal
     * assertions could drift apart while both stayed green; this one cannot.
     */
    public function testCountExpiredBeforeAgreesExactlyWithWhatTheSweepRemoves(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        $this->persistExpiringAt($threshold->modify('-1 second'));
        $this->persistExpiringAt($threshold->modify('-1 day'));
        $this->persistExpiringAt($threshold);
        $this->persistExpiringAt($threshold->modify('+1 second'));

        $counted = $this->repository->countExpiredBefore($threshold);
        $deleted = $this->repository->deleteExpiredBefore($threshold, 100);

        self::assertSame($counted, $deleted, 'The backlog count and the sweep must select the same rows.');
        self::assertSame(0, $this->repository->countExpiredBefore($threshold), 'A drained table has no backlog.');
    }

    /**
     * AC-5, the safety property: a **live** challenge is never deleted, at any limit.
     *
     * It holds by arithmetic rather than by a guard — a live row has `expiresAt >= now` and the
     * threshold is `now - 7 days` — but a property that holds by arithmetic must still fail loudly
     * the day the arithmetic changes, which is what this asserts.
     */
    public function testALiveRequestIsNeverDeleted(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $live = $this->persistExpiringAt($now->modify('+1 hour'));

        $this->repository->deleteExpiredBefore(EmailVerificationRequest::retentionThreshold($now), 100);

        self::assertTrue($this->rowExists($live));
    }

    /**
     * AC-6: a **redeemed** row still inside its retention window survives — which is the entire
     * reason the window exists.
     *
     * The row is dead by every business measure and is kept anyway, so that a replay produces "this
     * link was already used" rather than "no such link". Both give the visitor the same neutral
     * response; the difference lives in the log and in an incident review, which is where it is worth
     * something.
     *
     * Note what the sweep is NOT told: `redeemed_at` appears in no predicate (AC-7). This row is kept
     * because it is not yet overdue, not because it is redeemed — and a row that *is* overdue is
     * taken whether it was redeemed or not.
     */
    public function testARedeemedRequestInsideItsRetentionWindowIsNotDeleted(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        // Expired two days ago, so dead — but the window is seven days, so not yet overdue.
        $request = $this->persistExpiringAt($now->modify('-2 days'), redeemed: true);

        $this->repository->deleteExpiredBefore(EmailVerificationRequest::retentionThreshold($now), 100);

        self::assertTrue($this->rowExists($request), 'A redeemed row inside the retention window must survive.');
    }

    /**
     * AC-19: `$limit` caps the deletion, and the return value is asserted against the **observed row
     * delta** rather than against the literal 2.
     *
     * A test asserting `assertSame(2, $deleted)` would pass against a method that returned the limit
     * it was handed without deleting anything. Comparing the return value to the difference in a raw
     * `COUNT(*)` taken before and after is the assertion that cannot be satisfied by a lie.
     */
    public function testDeleteExpiredBeforeHonoursTheLimitAndReturnsTheRowsActuallyRemoved(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        for ($i = 1; $i <= 5; ++$i) {
            $this->persistExpiringAt($threshold->modify(\sprintf('-%d hours', $i)));
        }

        $before = $this->tableCount();
        $deleted = $this->repository->deleteExpiredBefore($threshold, 2);
        $after = $this->tableCount();

        self::assertSame($before - $after, $deleted, 'The return value must be the rows actually removed.');
        self::assertSame(2, $deleted);
        self::assertSame(3, $this->repository->countExpiredBefore($threshold), 'The remaining overdue rows are untouched.');
    }

    /**
     * AC-17: a row whose `token_hash` cannot be rehydrated into a valid value object is **still
     * deleted**, because nothing is hydrated.
     *
     * WHY THIS ROW IS WRITTEN WITH RAW SQL, SAID OUT LOUD BECAUSE IT BREAKS THIS SUITE'S OWN RULE.
     * Everywhere else a fixture goes through `issue()` via the Foundry factory, precisely so that no
     * test is built on a row the aggregate could never produce. This row is *deliberately* one the
     * aggregate could never produce: `HashedVerificationToken` refuses an empty string, so there is
     * no path through the model that creates this. Widening the factory to permit it would poison
     * every other test with the ability to build impossible states. Writing it here, in one place,
     * with this comment, is the honest way to say "we are outside the model on purpose".
     *
     * What it buys is the concrete cost of the rejected load-and-delete design: that alternative
     * would throw inside hydration on this one row, kill the run, and go on killing every run
     * afterwards while the backlog grew and nothing named the offender — silently, forever.
     */
    public function testARowWithACorruptTokenHashIsStillDeletedBecauseNothingIsHydrated(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $id = Uuid::v7()->toRfc4122();

        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO identity_email_verification_request
                    (id, user_id, token_hash, issued_at, expires_at, redeemed_at)
                VALUES (:id, :userId, '', :issuedAt, :expiresAt, NULL)
                SQL,
            [
                'id' => $id,
                'userId' => Uuid::v7()->toRfc4122(),
                'issuedAt' => $threshold->modify('-2 days')->format('Y-m-d H:i:sO'),
                'expiresAt' => $threshold->modify('-1 day')->format('Y-m-d H:i:sO'),
            ],
        );

        $deleted = $this->repository->deleteExpiredBefore($threshold, 100);

        self::assertSame(1, $deleted);
        self::assertFalse($this->rowExists($id), 'A corrupt row must be swept like any other, not stall the sweep.');
    }

    /**
     * AC-9: a row whose `expires_at − issued_at` disagrees with the **current** `LIFETIME_SECONDS`
     * is judged by its **stored** `expires_at`.
     *
     * This is what makes the sweep survive a future change to the lifetime with no backfill, no data
     * migration and no window in which old and new rows are judged differently. If the adapter ever
     * recomputed expiry from `issued_at` — an optimisation someone could plausibly reach for — this
     * row would be judged by today's constant and the answer would change.
     *
     * Raw SQL again, and for the same reason as above: `issue()` derives `expiresAt` and refuses to
     * take it as a parameter (invariant I-8), so a row whose two timestamps disagree with the
     * constant is by construction unreachable through the model. The row below carries a one-second
     * "lifetime" — nothing like the real 86 400 — and is overdue on its stored value while being
     * *not* overdue on a value recomputed from `issued_at`.
     */
    public function testARowIsJudgedByItsStoredExpiresAtRatherThanOneRecomputedFromIssuedAt(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $id = Uuid::v7()->toRfc4122();

        // issued_at is AFTER the threshold, so recomputing `issued_at + LIFETIME_SECONDS` would put
        // this row far in the future and spare it. Its stored expires_at is a day before the
        // threshold, so judging it correctly means deleting it.
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO identity_email_verification_request
                    (id, user_id, token_hash, issued_at, expires_at, redeemed_at)
                VALUES (:id, :userId, :hash, :issuedAt, :expiresAt, NULL)
                SQL,
            [
                'id' => $id,
                'userId' => Uuid::v7()->toRfc4122(),
                'hash' => hash('sha256', random_bytes(32)),
                'issuedAt' => $threshold->modify('+10 days')->format('Y-m-d H:i:sO'),
                'expiresAt' => $threshold->modify('-1 day')->format('Y-m-d H:i:sO'),
            ],
        );

        self::assertSame(1, $this->repository->countExpiredBefore($threshold));

        $deleted = $this->repository->deleteExpiredBefore($threshold, 100);

        self::assertSame(1, $deleted);
        self::assertFalse($this->rowExists($id));
    }

    /**
     * Persists a request whose `expiresAt` lands exactly on `$expiresAt`.
     *
     * `expiresAt` is derived inside `issue()` and is not a parameter (invariant I-8), so the only
     * lever is `issuedAt` and the arithmetic runs backwards through `LIFETIME_SECONDS`. That is
     * deliberate: the fixture bends to the aggregate rather than the aggregate bending to the
     * fixture.
     *
     * @return string the row's id, for a raw-SQL existence check afterwards
     */
    private function persistExpiringAt(\DateTimeImmutable $expiresAt, bool $redeemed = false): string
    {
        $id = $this->repository->nextIdentity();
        $issuedAt = $expiresAt->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS));

        $request = EmailVerificationRequest::issue($id, $this->aUserId(), $this->aHash(), $issuedAt);
        $request->releaseEvents();

        if ($redeemed) {
            $request->redeem($request->tokenHash(), $issuedAt->modify('+1 minute'));
        }

        $this->repository->save($request);
        self::assertEquals($expiresAt, $request->expiresAt(), 'Fixture arithmetic must land on the intended instant.');

        return $id->toString();
    }

    /**
     * Existence read straight from Postgres, never through the ORM.
     *
     * `deleteExpiredBefore()` bypasses the identity map entirely, so `find()` would happily return a
     * cached object for a row that has been deleted — the sharpest form of the identity-map footgun
     * CLAUDE.md documents, because here the stale object is the *whole* thing under test.
     */
    private function rowExists(string $id): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM identity_email_verification_request WHERE id = :id',
            ['id' => $id],
        );
    }

    private function tableCount(): int
    {
        /** @var int|numeric-string $count */
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM identity_email_verification_request');

        return (int) $count;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function aUserId(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }

    private function aHash(): HashedVerificationToken
    {
        return HashedVerificationToken::fromString(hash('sha256', random_bytes(32)));
    }
}
