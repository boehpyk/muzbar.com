<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
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

    private function aUserId(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }

    private function aHash(): HashedResetToken
    {
        return HashedResetToken::fromString(hash('sha256', random_bytes(32)));
    }
}
