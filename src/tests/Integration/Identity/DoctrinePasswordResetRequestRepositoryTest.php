<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `DoctrinePasswordResetRequestRepository` against the real `muzbar_test`
 * database (DAMA wraps each test in a transaction that rolls back — no mocked database, per
 * CLAUDE.md). Mirrors `DoctrineEmailVerificationRequestRepositoryTest`, plus the one query that has
 * no counterpart there: `findOutstandingForUser()`, which exists solely because issuing a reset link
 * invalidates the outstanding ones while issuing a verification link does not.
 */
final class DoctrinePasswordResetRequestRepositoryTest extends KernelTestCase
{
    private PasswordResetRequestRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $repository);
        $this->repository = $repository;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * The technical plan's "this is the test that catches a broken DBAL type": every value object —
     * not merely `tokenHash` — must round-trip through Postgres and back as an *equal* value, not
     * merely a non-null one. Clearing the identity map before `findByTokenHash()` forces a real
     * hydration from the database row rather than returning the same in-memory object.
     */
    public function testSaveThenFindByTokenHashRoundTripsEveryValueObjectWithEqualValuesAfterClear(): void
    {
        $id = $this->repository->nextIdentity();
        $userId = $this->aUserId();
        $hash = $this->aHash();
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $request = PasswordResetRequest::issue($id, $userId, $hash, $issuedAt);
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
        self::assertNull($found->invalidatedAt());
        self::assertFalse($found->isRedeemed());
        self::assertFalse($found->isInvalidated());
    }

    /**
     * The nullable `redeemed_at` round-trips too, once set — the same "not merely not-null, but the
     * same instant" guarantee as the verification suite (gotcha: compare via `DateTimeImmutable`
     * equality, never the timezone name — Postgres reads back `+00:00`, not the literal `UTC`).
     */
    public function testSaveThenFindByTokenHashRoundTripsARedeemedRequest(): void
    {
        $hash = $this->aHash();
        $request = PasswordResetRequest::issue(
            $this->repository->nextIdentity(),
            $this->aUserId(),
            $hash,
            new \DateTimeImmutable('2026-07-28T10:00:00+00:00'),
        );
        $redeemedAt = new \DateTimeImmutable('2026-07-28T10:30:00+00:00');
        $request->redeem($hash, $redeemedAt);
        $this->repository->save($request);

        $this->entityManager->clear();

        $found = $this->repository->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->isRedeemed());
        self::assertEquals($redeemedAt, $found->redeemedAt());
        self::assertNull($found->invalidatedAt());
    }

    /**
     * The nullable `invalidated_at` round-trips too, once set.
     */
    public function testSaveThenFindByTokenHashRoundTripsAnInvalidatedRequest(): void
    {
        $hash = $this->aHash();
        $request = PasswordResetRequest::issue(
            $this->repository->nextIdentity(),
            $this->aUserId(),
            $hash,
            new \DateTimeImmutable('2026-07-28T10:00:00+00:00'),
        );
        $invalidatedAt = new \DateTimeImmutable('2026-07-28T10:05:00+00:00');
        $request->invalidate($invalidatedAt);
        $this->repository->save($request);

        $this->entityManager->clear();

        $found = $this->repository->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->isInvalidated());
        self::assertEquals($invalidatedAt, $found->invalidatedAt());
        self::assertNull($found->redeemedAt());
    }

    public function testFindByTokenHashReturnsNullForAHashThatWasNeverSaved(): void
    {
        self::assertNull($this->repository->findByTokenHash($this->aHash()));
    }

    /**
     * `findByTokenHash()` returns a request whether it is redeemed, invalidated or expired — the
     * repository must never let dead rows collapse into the same `null` as "never existed", or the
     * system loses the ability to distinguish "expired yesterday" from "never issued" (technical
     * plan, port docblock).
     */
    public function testFindByTokenHashReturnsAnExpiredRequestRatherThanNull(): void
    {
        $hash = $this->aHash();
        $request = PasswordResetRequest::issue(
            $this->repository->nextIdentity(),
            $this->aUserId(),
            $hash,
            (new \DateTimeImmutable('2026-07-28T10:00:00+00:00'))->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS + 3600)),
        );
        $this->repository->save($request);

        $this->entityManager->clear();

        $found = $this->repository->findByTokenHash($hash);

        self::assertNotNull($found);
        self::assertTrue($found->isExpiredAt(new \DateTimeImmutable('2026-07-28T10:00:00+00:00')));
    }

    /**
     * `countIssuedForUserSince` boundary behaviour: a request issued exactly *at* `$since` counts
     * (the DQL uses `>=`), one issued a second before does not. Both sides are asserted, or the test
     * proves nothing about which operator is in the query.
     */
    public function testCountIssuedForUserSinceIncludesTheBoundaryInstantAndExcludesOneSecondBeforeIt(): void
    {
        $userId = $this->aUserId();
        $since = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $atBoundary = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since);
        $this->repository->save($atBoundary);

        $beforeBoundary = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since->modify('-1 second'));
        $this->repository->save($beforeBoundary);

        self::assertSame(1, $this->repository->countIssuedForUserSince($userId, $since));
    }

    /**
     * `countIssuedForUserSince` counts only the given user's rows — a request belonging to a
     * different user must never be counted, however recently it was issued.
     */
    public function testCountIssuedForUserSinceCountsOnlyTheGivenUsersRequests(): void
    {
        $userId = $this->aUserId();
        $otherUserId = $this->aUserId();
        $since = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $this->repository->save(PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $since));
        $this->repository->save(PasswordResetRequest::issue($this->repository->nextIdentity(), $otherUserId, $this->aHash(), $since));
        $this->repository->save(PasswordResetRequest::issue($this->repository->nextIdentity(), $otherUserId, $this->aHash(), $since->modify('+1 minute')));

        self::assertSame(1, $this->repository->countIssuedForUserSince($userId, $since));
        self::assertSame(2, $this->repository->countIssuedForUserSince($otherUserId, $since));
    }

    public function testCountIssuedForUserSinceIsZeroForAUserWithNoRequests(): void
    {
        self::assertSame(0, $this->repository->countIssuedForUserSince($this->aUserId(), new \DateTimeImmutable()));
    }

    /**
     * `findOutstandingForUser()` excludes a redeemed row and an invalidated row alike — it filters on
     * **structure** (`redeemed_at IS NULL AND invalidated_at IS NULL`), which is the aggregate's own
     * recorded state.
     */
    public function testFindOutstandingForUserExcludesRedeemedAndInvalidatedRows(): void
    {
        $userId = $this->aUserId();
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $redeemedHash = $this->aHash();
        $redeemed = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $redeemedHash, $issuedAt);
        $redeemed->redeem($redeemedHash, $issuedAt->modify('+1 minute'));
        $this->repository->save($redeemed);

        $invalidated = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $issuedAt->modify('+2 minutes'));
        $invalidated->invalidate($issuedAt->modify('+3 minutes'));
        $this->repository->save($invalidated);

        self::assertSame([], $this->repository->findOutstandingForUser($userId));
    }

    /**
     * `findOutstandingForUser()` **includes an expired-but-otherwise-untouched row.** This is the
     * interesting half of the contract: the repository filters on structure, never on judgement
     * (expiry requires a clock the repository must not reach for), so an expired request is still
     * "outstanding" until something — the reissue sweep's `invalidate()` — actually closes it. A
     * repository that pre-filtered expiry here would make clock arithmetic leak into SQL and would
     * make the sweep's own idempotent `invalidate()` call on an expired row unreachable in this test.
     */
    public function testFindOutstandingForUserIncludesAnExpiredRequest(): void
    {
        $userId = $this->aUserId();

        $expired = PasswordResetRequest::issue(
            $this->repository->nextIdentity(),
            $userId,
            $this->aHash(),
            (new \DateTimeImmutable('2026-07-28T10:00:00+00:00'))->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS + 3600)),
        );
        $this->repository->save($expired);

        $outstanding = $this->repository->findOutstandingForUser($userId);

        self::assertCount(1, $outstanding);
        self::assertTrue($outstanding[0]->id()->equals($expired->id()));
    }

    /**
     * `findOutstandingForUser()` returns only the given user's rows.
     */
    public function testFindOutstandingForUserReturnsOnlyTheGivenUsersRequests(): void
    {
        $userId = $this->aUserId();
        $otherUserId = $this->aUserId();
        $issuedAt = new \DateTimeImmutable('2026-07-28T10:00:00+00:00');

        $mine = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), $issuedAt);
        $this->repository->save($mine);

        $someoneElses = PasswordResetRequest::issue($this->repository->nextIdentity(), $otherUserId, $this->aHash(), $issuedAt);
        $this->repository->save($someoneElses);

        $outstanding = $this->repository->findOutstandingForUser($userId);

        self::assertCount(1, $outstanding);
        self::assertTrue($outstanding[0]->id()->equals($mine->id()));
    }

    /**
     * `findOutstandingForUser()`'s `ORDER BY issued_at` is part of the port's contract (see the
     * adapter's own docblock) rather than decoration, and the single-row tests above cannot exercise
     * an order at all — three rows saved deliberately **out of `issuedAt` order** is what actually
     * pins that the DQL sorts ascending rather than merely returning membership, and it is what would
     * catch the `ORDER BY` clause being "optimised" away or reversed.
     */
    public function testFindOutstandingForUserOrdersByIssuedAtAscendingRegardlessOfSaveOrder(): void
    {
        $userId = $this->aUserId();
        $middle = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), new \DateTimeImmutable('2026-07-28T10:05:00+00:00'));
        $latest = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), new \DateTimeImmutable('2026-07-28T10:10:00+00:00'));
        $earliest = PasswordResetRequest::issue($this->repository->nextIdentity(), $userId, $this->aHash(), new \DateTimeImmutable('2026-07-28T10:00:00+00:00'));

        // Saved in an order that does NOT match `issuedAt` order, so a query with no `ORDER BY` (or
        // one that merely reflects insertion order) would not accidentally pass this test.
        $this->repository->save($middle);
        $this->repository->save($latest);
        $this->repository->save($earliest);

        $outstanding = $this->repository->findOutstandingForUser($userId);

        self::assertCount(3, $outstanding);
        self::assertTrue($outstanding[0]->id()->equals($earliest->id()), 'The earliest-issued request must come first.');
        self::assertTrue($outstanding[1]->id()->equals($middle->id()), 'The middle-issued request must come second.');
        self::assertTrue($outstanding[2]->id()->equals($latest->id()), 'The latest-issued request must come last.');
    }

    public function testNextIdentityReturnsDistinctValidIds(): void
    {
        $ids = array_map(
            fn (): PasswordResetRequestId => $this->repository->nextIdentity(),
            range(1, 5),
        );

        $unique = array_unique(array_map(static fn (PasswordResetRequestId $id): string => $id->toString(), $ids));

        self::assertCount(5, $unique);
    }

    /**
     * AC-4, **both sides of the boundary**, on the second table. A row at `threshold − 1 s` is
     * deleted; rows at exactly `threshold` and at `threshold + 1 s` are kept.
     *
     * Asserted independently of the verification adapter's identical test rather than shared, for
     * the same reason the two port methods are declared separately: the two adapters are two
     * implementations that happen to agree today, and a test that covered both through one helper
     * would go on passing if one of them stopped agreeing.
     *
     * Survivors are read with raw SQL — `deleteExpiredBefore()` runs native SQL and never tells the
     * identity map the rows are gone.
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
     * The count and the sweep select the same rows, asserted against **each other** rather than
     * against two literals that could drift apart while both stayed green.
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

        self::assertSame($counted, $deleted);
        self::assertSame(0, $this->repository->countExpiredBefore($threshold));
    }

    /**
     * AC-5 on the table where deleting a live row would matter most: a live **reset** challenge is
     * never swept.
     *
     * Deleting a live reset row would be catastrophic and silent — the user's recovery link stops
     * working, they see the same neutral invalid-link response as for a forged token, and nothing
     * anywhere says why. It is structurally impossible (the threshold is strictly before `now` and a
     * live row expires at or after it), and pinned here so the impossibility fails loudly the day the
     * arithmetic changes.
     */
    public function testALiveRequestIsNeverDeleted(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $live = $this->persistExpiringAt($now->modify('+30 minutes'));

        $this->repository->deleteExpiredBefore(PasswordResetRequest::retentionThreshold($now), 100);

        self::assertTrue($this->rowExists($live));
    }

    /**
     * AC-6: a **redeemed** reset row inside its thirty-day window survives, which is what keeps a
     * replay answering `PasswordResetLinkAlreadyUsed` instead of `PasswordResetRequestNotFound`.
     *
     * Both answers give the visitor the identical neutral response by design, so the whole value of
     * the distinction is in the log and in an incident review — which is precisely the question this
     * aggregate's thirty days were chosen to keep answerable.
     */
    public function testARedeemedRequestInsideItsRetentionWindowIsNotDeleted(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');

        // Expired a week ago, so long dead — but the window here is thirty days.
        $request = $this->persistExpiringAt($now->modify('-7 days'), redeemed: true);

        $this->repository->deleteExpiredBefore(PasswordResetRequest::retentionThreshold($now), 100);

        self::assertTrue($this->rowExists($request));
    }

    /**
     * AC-7: an **invalidated but unexpired** row survives — the case that exists only on this
     * aggregate, because only this one invalidates its siblings on reissue.
     *
     * This is the sharpest available proof that the tables' disagreement about "dead" is encoded
     * nowhere. The row is, by this aggregate's own rules, as finished as a row can be: superseded,
     * never redeemable again, occupying space for nothing. A predicate that knew what "dead" meant
     * would take it, and taking it would be the shared abstraction ADR-0012 decision 1 refused,
     * rebuilt in SQL where no unit test could reach it. The sweep does not know and does not ask: the
     * row is not overdue on `expires_at`, so it stays.
     */
    public function testAnInvalidatedButUnexpiredRequestIsNotDeleted(): void
    {
        $now = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $request = $this->persistExpiringAt($now->modify('+30 minutes'), invalidated: true);

        $this->repository->deleteExpiredBefore(PasswordResetRequest::retentionThreshold($now), 100);

        self::assertTrue($this->rowExists($request), 'An invalidated row is not an overdue row — the sweep judges expiry alone.');
    }

    /**
     * AC-19: `$limit` caps the deletion, and the return value is asserted against the **observed row
     * delta**, never against the literal — an assertion against a literal would pass for a method
     * that returned the limit it was handed without deleting anything.
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

        self::assertSame($before - $after, $deleted);
        self::assertSame(2, $deleted);
        self::assertSame(3, $this->repository->countExpiredBefore($threshold));
    }

    /**
     * AC-17 on the reset table: a corrupt `token_hash` is still deleted, because nothing is
     * hydrated.
     *
     * WRITTEN WITH RAW SQL ON PURPOSE, AND THE SUITE'S OWN RULE IS BEING BROKEN KNOWINGLY. Every
     * other fixture here goes through `issue()` so that no test rests on a row the aggregate could
     * not produce. This row is exactly that: `HashedResetToken` refuses an empty string, so no path
     * through the model reaches it. Widening the factory would hand every other test the ability to
     * build impossible states; writing it once, here, with this comment, says plainly that we are
     * outside the model deliberately.
     *
     * What it proves is the cost of the design that was not chosen. Load-and-delete would throw
     * inside hydration on this row, kill the run, and keep killing every subsequent run while the
     * backlog grew and nothing named the offending row.
     */
    public function testARowWithACorruptTokenHashIsStillDeletedBecauseNothingIsHydrated(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $id = Uuid::v7()->toRfc4122();

        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO identity_password_reset_request
                    (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)
                VALUES (:id, :userId, '', :issuedAt, :expiresAt, NULL, NULL)
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
        self::assertFalse($this->rowExists($id));
    }

    /**
     * AC-9: the sweep reads the **stored** `expires_at` rather than recomputing it from `issued_at`,
     * so it stays correct across a future change to `LIFETIME_SECONDS` with no backfill.
     *
     * Raw SQL because `issue()` derives `expiresAt` and refuses it as a parameter (I-15), making a
     * row whose timestamps disagree with the constant unreachable through the model. This row's
     * `issued_at` sits *after* the threshold, so a recomputed expiry would spare it, while its stored
     * `expires_at` is a day before the threshold and must not.
     */
    public function testARowIsJudgedByItsStoredExpiresAtRatherThanOneRecomputedFromIssuedAt(): void
    {
        $threshold = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $id = Uuid::v7()->toRfc4122();

        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO identity_password_reset_request
                    (id, user_id, token_hash, issued_at, expires_at, redeemed_at, invalidated_at)
                VALUES (:id, :userId, :hash, :issuedAt, :expiresAt, NULL, NULL)
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
        self::assertSame(1, $this->repository->deleteExpiredBefore($threshold, 100));
        self::assertFalse($this->rowExists($id));
    }

    /**
     * Persists a request whose `expiresAt` lands exactly on `$expiresAt`.
     *
     * `expiresAt` is derived inside `issue()` and is not a parameter (invariant I-15), so the lever
     * is `issuedAt` and the arithmetic runs backwards through `LIFETIME_SECONDS`. The fixture bends
     * to the aggregate, never the reverse — and note that `redeemed` and `invalidated` go through the
     * aggregate's own methods, so a state I-17 forbids cannot be built here even by accident.
     *
     * @return string the row's id, for a raw-SQL existence check afterwards
     */
    private function persistExpiringAt(
        \DateTimeImmutable $expiresAt,
        bool $redeemed = false,
        bool $invalidated = false,
    ): string {
        $id = $this->repository->nextIdentity();
        $issuedAt = $expiresAt->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS));

        $request = PasswordResetRequest::issue($id, $this->aUserId(), $this->aHash(), $issuedAt);
        $request->releaseEvents();

        if ($redeemed) {
            $request->redeem($request->tokenHash(), $issuedAt->modify('+1 minute'));
        }

        if ($invalidated) {
            $request->invalidate($issuedAt->modify('+1 minute'));
        }

        $this->repository->save($request);
        self::assertEquals($expiresAt, $request->expiresAt(), 'Fixture arithmetic must land on the intended instant.');

        return $id->toString();
    }

    /**
     * Existence read straight from Postgres, never through the ORM: `deleteExpiredBefore()` bypasses
     * the identity map, so `find()` would return a cached object for a deleted row.
     */
    private function rowExists(string $id): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM identity_password_reset_request WHERE id = :id',
            ['id' => $id],
        );
    }

    private function tableCount(): int
    {
        /** @var int|numeric-string $count */
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM identity_password_reset_request');

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

    private function aHash(): HashedResetToken
    {
        return HashedResetToken::fromString(hash('sha256', random_bytes(32)));
    }
}
