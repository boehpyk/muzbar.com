<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `DoctrineUserRepository` against the real `muzbar_test` database (DAMA
 * wraps each test in a transaction that rolls back — no mocked database, per CLAUDE.md).
 */
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * AC-9's prerequisite and the technical plan's "this is the test that catches a broken DBAL
     * type": every value object must round-trip through Postgres and back as an *equal* value,
     * not merely a non-null one. Clearing the identity map before `findById()` forces a real
     * hydration from the database row rather than returning the same in-memory object.
     */
    public function testSaveThenFindByIdRoundTripsEveryValueObjectWithEqualValues(): void
    {
        $id = $this->repository->nextIdentity();
        $email = Email::fromString('round-trip@example.com');
        $hash = HashedPassword::fromString('$2y$04$'.str_repeat('a', 53));
        $registeredAt = new \DateTimeImmutable('2026-07-25T10:15:00+00:00');

        $user = User::register($id, $email, $hash, $registeredAt);
        $this->repository->save($user);

        $this->entityManager->clear();

        $found = $this->repository->findById($id);

        self::assertNotNull($found);
        self::assertNotSame($user, $found, 'clear() must force a fresh hydration, not return the same object');
        self::assertTrue($found->id()->equals($id));
        self::assertTrue($found->email()->equals($email));
        self::assertTrue($found->passwordHash()->equals($hash));
        self::assertSame([Role::User], $found->roles());
        self::assertNull($found->emailVerifiedAt());
        self::assertEquals($registeredAt, $found->registeredAt());
    }

    /**
     * The `emailVerifiedAt` timestamp round-trips too, once set — proving the nullable
     * `datetimetz_immutable` column is not merely "not null" but the same instant (gotcha:
     * compare via `DateTimeImmutable` equality, never the timezone name — Postgres reads back
     * `+00:00`, not the literal `UTC`).
     */
    public function testSaveThenFindByIdRoundTripsAVerifiedTimestamp(): void
    {
        $id = $this->repository->nextIdentity();
        $verifiedAt = new \DateTimeImmutable('2026-07-26T08:00:00+00:00');
        $user = User::register($id, Email::fromString('verified-round-trip@example.com'), $this->aHash(), new \DateTimeImmutable('2026-07-25T10:00:00+00:00'));
        $user->verifyEmail($verifiedAt);
        $this->repository->save($user);

        $this->entityManager->clear();

        $found = $this->repository->findById($id);

        self::assertNotNull($found);
        self::assertTrue($found->isEmailVerified());
        self::assertEquals($verifiedAt, $found->emailVerifiedAt());
    }

    /**
     * AC-4's persistence half: `EmailType` normalises on the way down and on the way up, so a
     * mixed-case, whitespace-padded query matches the lower-cased stored row without any `LOWER()`
     * in the SQL.
     */
    public function testFindByEmailMatchesAMixedCaseQueryAgainstTheLowerCasedStoredRow(): void
    {
        $user = User::register(
            $this->repository->nextIdentity(),
            Email::fromString('max@example.com'),
            $this->aHash(),
            new \DateTimeImmutable(),
        );
        $this->repository->save($user);
        $this->entityManager->clear();

        $found = $this->repository->findByEmail(Email::fromString(' Max@Example.COM '));

        self::assertNotNull($found);
        self::assertSame('max@example.com', $found->email()->toString());
    }

    public function testExistsByEmailMatchesAMixedCaseQueryAgainstTheLowerCasedStoredRow(): void
    {
        $user = User::register(
            $this->repository->nextIdentity(),
            Email::fromString('exists-check@example.com'),
            $this->aHash(),
            new \DateTimeImmutable(),
        );
        $this->repository->save($user);
        $this->entityManager->clear();

        self::assertTrue($this->repository->existsByEmail(Email::fromString('EXISTS-CHECK@EXAMPLE.COM')));
    }

    public function testExistsByEmailIsFalseForAnEmailThatWasNeverSaved(): void
    {
        self::assertFalse($this->repository->existsByEmail(Email::fromString('never-registered@example.com')));
    }

    public function testFindByEmailReturnsNullForAnUnknownEmail(): void
    {
        self::assertNull($this->repository->findByEmail(Email::fromString('unknown@example.com')));
    }

    public function testFindByIdReturnsNullForAnIdThatWasNeverSaved(): void
    {
        $neverSaved = $this->repository->nextIdentity();

        self::assertNull($this->repository->findById($neverSaved));
    }

    /**
     * AC-9: a duplicate that slips past the application-level pre-check is caught by the unique
     * index and surfaces as the domain exception, never a raw Doctrine/DBAL exception.
     */
    public function testSavingASecondUserWithTheSameEmailRaisesEmailAlreadyRegistered(): void
    {
        $email = Email::fromString('duplicate@example.com');
        $first = User::register($this->repository->nextIdentity(), $email, $this->aHash(), new \DateTimeImmutable());
        $this->repository->save($first);

        $second = User::register($this->repository->nextIdentity(), $email, $this->aHash(), new \DateTimeImmutable());

        $this->expectException(EmailAlreadyRegistered::class);

        $this->repository->save($second);
    }

    public function testNextIdentityReturnsDistinctIds(): void
    {
        $ids = array_map(
            fn (): UserId => $this->repository->nextIdentity(),
            range(1, 5),
        );

        $unique = array_unique(array_map(static fn (UserId $id): string => $id->toString(), $ids));

        self::assertCount(5, $unique);
    }

    /**
     * UUIDv7s are time-ordered by construction (Symfony's generator increments a monotonic
     * counter within the same millisecond), so successive calls must sort lexicographically —
     * which is exactly the property that keeps B-tree inserts on the right-hand edge of the page.
     */
    public function testNextIdentityReturnsTimeOrderedIds(): void
    {
        $ids = array_map(
            fn (): string => $this->repository->nextIdentity()->toString(),
            range(1, 5),
        );

        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertSame($sorted, $ids);
    }

    private function aHash(): HashedPassword
    {
        return HashedPassword::fromString('$2y$04$'.str_repeat('a', 53));
    }
}
