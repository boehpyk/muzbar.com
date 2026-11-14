<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Persistence of the OAuth additions to `User` against the real `muzbar_test` database: the
 * nullable password hash, the `oauth_identities` jsonb column, and `findByOAuthIdentity()`.
 */
final class DoctrineUserRepositoryOAuthTest extends KernelTestCase
{
    private UserRepository $repository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $this->repository = $repository;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    // ---- null password hash + identities round trip (AC-6, AC-21) -----------------------------

    public function testAProvisionedUserRoundTripsWithANullHashAndItsIdentity(): void
    {
        $id = $this->repository->nextIdentity();
        $identity = OAuthIdentity::of(OAuthProvider::Google, 'sub-round-trip');
        $registeredAt = new \DateTimeImmutable('2026-11-14T09:00:00+00:00');
        $this->repository->save(User::registerWithOAuth($id, Email::fromString('provisioned@example.com'), $identity, $registeredAt));

        $this->entityManager->clear();
        $found = $this->repository->findById($id);

        self::assertNotNull($found);
        self::assertNull($found->passwordHash());
        self::assertFalse($found->hasPassword());
        self::assertCount(1, $found->oauthIdentities());
        self::assertTrue($found->hasOAuthIdentity($identity));
        self::assertEquals($registeredAt, $found->emailVerifiedAt());
    }

    public function testAProvisionedUserIsStoredWithSqlNullAndExactlyTheTwoDocumentedKeys(): void
    {
        $id = $this->repository->nextIdentity();
        $this->repository->save(User::registerWithOAuth(
            $id,
            Email::fromString('raw-row@example.com'),
            OAuthIdentity::of(OAuthProvider::Google, 'sub-raw'),
            new \DateTimeImmutable('2026-11-14T09:00:00+00:00'),
        ));

        $row = $this->connection->fetchAssociative(
            'SELECT password_hash IS NULL AS no_hash, oauth_identities::text AS identities, roles::text AS roles FROM identity_user WHERE id = :id',
            ['id' => $id->toString()],
        );

        self::assertIsArray($row);
        self::assertTrue($row['no_hash']);
        self::assertIsString($row['identities']);
        self::assertIsString($row['roles']);
        self::assertJsonStringEqualsJsonString('[{"provider":"google","subject":"sub-raw"}]', $row['identities']);
        self::assertJsonStringEqualsJsonString('["ROLE_USER"]', $row['roles']);
    }

    public function testTheTableHoldsNothingFromGoogleBeyondTheSubject(): void
    {
        $columns = array_map(
            static fn ($column): string => $column->getObjectName()->toString(),
            $this->connection->createSchemaManager()->introspectTable('identity_user')->getColumns(),
        );
        sort($columns);

        self::assertSame(
            ['email', 'email_verified_at', 'id', 'oauth_identities', 'password_changed_at', 'password_hash', 'registered_at', 'roles'],
            $columns,
            'AC-21: no column for name, picture, tokens or locale.',
        );
    }

    public function testAPasswordUserRoundTripsWithNoIdentitiesAndItsHash(): void
    {
        $user = UserFactory::new()->create();
        $hash = $user->passwordHash();
        self::assertInstanceOf(HashedPassword::class, $hash);

        $this->entityManager->clear();
        $found = $this->repository->findById($user->id());

        self::assertNotNull($found);
        self::assertSame([], $found->oauthIdentities());
        self::assertTrue($found->passwordHash()?->equals($hash));
        self::assertSame(
            '[]',
            $this->connection->fetchOne('SELECT oauth_identities::text FROM identity_user WHERE id = :id', ['id' => $user->id()->toString()]),
        );
    }

    public function testADiscardedPasswordIsPersistedAsNullAfterLinking(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('discard-persist@example.com')])->create();
        $found = $this->repository->findById($user->id());
        self::assertNotNull($found);

        $found->linkOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-link'), new \DateTimeImmutable('2026-11-14T10:00:00+00:00'));
        $this->repository->save($found);
        $this->entityManager->clear();

        $reloaded = $this->repository->findById($user->id());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->passwordHash());
        self::assertTrue($reloaded->isEmailVerified());
        self::assertTrue($reloaded->hasOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-link')));
    }

    public function testAnIdentityListWithAnUnknownProviderRefusesToHydrateTheUser(): void
    {
        $user = UserFactory::new()->create();
        $this->connection->executeStatement(
            'UPDATE identity_user SET oauth_identities = CAST(:v AS jsonb) WHERE id = :id',
            ['v' => '[{"provider":"github","subject":"x"}]', 'id' => $user->id()->toString()],
        );
        $this->entityManager->clear();

        $this->expectException(ValueNotConvertible::class);

        $this->repository->findById($user->id());
    }

    // ---- findByOAuthIdentity (AC-5) ---------------------------------------------------------

    public function testFindByOAuthIdentityReturnsTheUserHoldingIt(): void
    {
        $holder = UserFactory::new()->provisionedViaGoogle('sub-holder')->create();
        UserFactory::new()->provisionedViaGoogle('sub-bystander')->create();
        $this->entityManager->clear();

        $found = $this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-holder'));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($holder->id()));
    }

    public function testFindByOAuthIdentityFindsAUserWhoLinkedGoogleToAnExistingAccount(): void
    {
        $holder = UserFactory::new()->verified()->linkedToGoogle('sub-linked')->create();
        $this->entityManager->clear();

        $found = $this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-linked'));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($holder->id()));
        self::assertTrue($found->hasPassword());
    }

    public function testFindByOAuthIdentityReturnsNullForAnUnknownSubject(): void
    {
        UserFactory::new()->provisionedViaGoogle('sub-known')->create();

        self::assertNull($this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-unknown')));
    }

    public function testFindByOAuthIdentityIsCaseSensitiveOnTheSubject(): void
    {
        UserFactory::new()->provisionedViaGoogle('SubCase')->create();

        self::assertNull($this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'subcase')));
    }

    public function testFindByOAuthIdentityDoesNotMatchTheSameSubjectUnderAnotherProvider(): void
    {
        $user = UserFactory::new()->create();
        $this->connection->executeStatement(
            'UPDATE identity_user SET oauth_identities = CAST(:v AS jsonb) WHERE id = :id',
            ['v' => '[{"provider":"github","subject":"shared-sub"}]', 'id' => $user->id()->toString()],
        );
        $this->entityManager->clear();

        self::assertNull($this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'shared-sub')));
    }

    public function testFindByOAuthIdentityDoesNotMatchOnAnEmailLookingSubject(): void
    {
        UserFactory::new(['email' => Email::fromString('looks@example.com')])->create();

        self::assertNull($this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'looks@example.com')));
    }

    public function testFindByOAuthIdentityTreatsQuotesAndBackslashesInTheSubjectAsData(): void
    {
        $subject = '"}],{"x\\';
        $holder = UserFactory::new()->provisionedViaGoogle($subject)->create();
        $this->entityManager->clear();

        $found = $this->repository->findByOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, $subject));

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($holder->id()));
    }
}
