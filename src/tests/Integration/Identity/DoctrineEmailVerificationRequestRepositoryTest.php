<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
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

    private function aUserId(): UserId
    {
        return UserId::fromString(Uuid::v7()->toRfc4122());
    }

    private function aHash(): HashedVerificationToken
    {
        return HashedVerificationToken::fromString(hash('sha256', random_bytes(32)));
    }
}
