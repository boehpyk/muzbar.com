<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\SignInWithOAuth;
use App\Application\Identity\Handler\SignInWithOAuthHandler;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserOAuthIdentityLinked;
use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\InvalidOAuthIdentity;
use App\Domain\Identity\Exception\OAuthEmailNotVerified;
use App\Domain\Identity\Exception\OAuthProviderAlreadyLinked;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use App\Tests\Fixture\StaleEmailLookupUserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `SignInWithOAuthHandler` against the real `muzbar_test` database (DAMA rollback), with a frozen
 * `Clock` and a spy dispatcher: recognise, the verified gate, link (verified and unverified) and
 * provision — plus the failure contract's refusals and the provision race.
 */
final class SignInWithOAuthHandlerTest extends KernelTestCase
{
    private const string NOW = '2026-11-14T12:00:00+00:00';

    private UserRepository $users;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private SignInWithOAuthHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->clock = new FrozenClock(new \DateTimeImmutable(self::NOW));
        $this->events = new SpyDomainEventDispatcher();
        $this->handler = new SignInWithOAuthHandler($this->users, $this->clock, $this->events);
    }

    // ---- recognise (AC-5) ----------------------------------------------------------------------

    public function testARecognisedSubjectSignsInTheAccountWithoutAnyWriteOrEvent(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('account@example.com')])->provisionedViaGoogle('sub-known')->create();
        $before = $this->rowOf($user->id()->toString());

        $email = ($this->handler)($this->command('sub-known', 'account@example.com'));

        self::assertSame('account@example.com', $email->toString());
        self::assertSame([], $this->events->dispatched());
        self::assertSame($before, $this->rowOf($user->id()->toString()));
        self::assertSame(1, $this->userCount());
    }

    public function testARecognisedSubjectWinsEvenWhenTheAssertedEmailDiffersAndIsNoLongerVerified(): void
    {
        UserFactory::new(['email' => Email::fromString('original@example.com')])->provisionedViaGoogle('sub-known')->create();

        $email = ($this->handler)($this->command('sub-known', 'changed-at-google@example.com', emailVerified: false));

        self::assertSame('original@example.com', $email->toString(), 'The sign-in identity is the ACCOUNT\'s email, not the asserted one.');
        self::assertSame([], $this->events->dispatched());
        self::assertSame(1, $this->userCount());
        self::assertFalse($this->users->existsByEmail(Email::fromString('changed-at-google@example.com')));
    }

    // ---- provision (AC-6) ----------------------------------------------------------------------

    public function testAnUnknownSubjectWithAVerifiedUnclaimedEmailProvisionsExactlyOnePasswordlessUser(): void
    {
        $email = ($this->handler)($this->command('sub-new', 'New.Musician@Example.COM'));

        self::assertSame('new.musician@example.com', $email->toString());
        self::assertSame(1, $this->userCount());

        $row = $this->rowByEmail('new.musician@example.com');
        self::assertNull($row['password_hash']);
        self::assertIsString($row['roles']);
        self::assertIsString($row['oauth_identities']);
        self::assertJsonStringEqualsJsonString('["ROLE_USER"]', $row['roles']);
        self::assertJsonStringEqualsJsonString('[{"provider":"google","subject":"sub-new"}]', $row['oauth_identities']);
        self::assertNotNull($row['email_verified_at']);
        self::assertSame($row['registered_at'], $row['email_verified_at']);
        self::assertNull($row['password_changed_at']);

        $user = $this->users->findByEmail($email);
        self::assertNotNull($user);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $user->registeredAt(), 'registeredAt comes from the Clock port.');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $user->emailVerifiedAt());
        self::assertTrue($user->isUsable());
    }

    public function testProvisioningDispatchesUserRegisteredThenUserEmailVerified(): void
    {
        ($this->handler)($this->command('sub-new', 'order@example.com'));

        $dispatched = $this->events->dispatched();
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(UserRegistered::class, $dispatched[0]);
        self::assertInstanceOf(UserEmailVerified::class, $dispatched[1]);
        self::assertTrue($dispatched[0]->userId()->equals($dispatched[1]->userId()));
    }

    // ---- link to a verified account (AC-8, AC-10) ----------------------------------------------

    public function testAnUnknownSubjectWithAVerifiedAccountsEmailLinksWithoutTouchingThePassword(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('verified@example.com')])->verified()->create();
        $hash = $existing->passwordHash()?->toString();
        $verifiedAt = $existing->emailVerifiedAt()?->getTimestamp();
        self::assertNotNull($verifiedAt);

        $email = ($this->handler)($this->command('sub-link', 'verified@example.com'));

        self::assertSame('verified@example.com', $email->toString());
        self::assertSame(1, $this->userCount(), 'Linking must never create a second User.');
        $this->entityManager->clear();
        $reloaded = $this->users->findById($existing->id());
        self::assertNotNull($reloaded);
        self::assertSame($hash, $reloaded->passwordHash()?->toString());
        self::assertSame($verifiedAt, $reloaded->emailVerifiedAt()?->getTimestamp(), 'Linking must not move an existing verification instant.');
        self::assertTrue($reloaded->hasOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-link')));

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserOAuthIdentityLinked::class, $dispatched[0]);
        self::assertFalse($dispatched[0]->passwordDiscarded());
    }

    public function testAMixedCaseGoogleEmailLinksToTheAccountRegisteredInLowerCase(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('musician@example.com')])->verified()->create();

        ($this->handler)($this->command('sub-case', 'Musician@Example.COM'));

        self::assertSame(1, $this->userCount());
        $this->entityManager->clear();
        $reloaded = $this->users->findById($existing->id());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-case')));
    }

    // ---- link to an unverified account: the pre-hijack defence (AC-12) -------------------------

    public function testLinkingToAnUnverifiedPasswordAccountDiscardsItsPasswordAndVerifiesTheEmail(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('victim@example.com')])->create();
        self::assertTrue($existing->hasPassword());

        ($this->handler)($this->command('sub-victim', 'victim@example.com'));

        self::assertSame(1, $this->userCount());
        $row = $this->rowOf($existing->id()->toString());
        self::assertNull($row['password_hash'], 'The attacker-chosen password must not survive the link.');
        self::assertNotNull($row['email_verified_at']);
        self::assertNull($row['password_changed_at']);

        $this->entityManager->clear();
        $reloaded = $this->users->findById($existing->id());
        self::assertNotNull($reloaded);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $reloaded->emailVerifiedAt());
        self::assertTrue($reloaded->isUsable());
    }

    public function testLinkingToAnUnverifiedAccountDispatchesEmailVerifiedThenLinkedWithTheDiscardFlagAndNoPasswordChange(): void
    {
        UserFactory::new(['email' => Email::fromString('victim@example.com')])->create();

        ($this->handler)($this->command('sub-victim', 'victim@example.com'));

        $dispatched = $this->events->dispatched();
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(UserEmailVerified::class, $dispatched[0]);
        self::assertInstanceOf(UserOAuthIdentityLinked::class, $dispatched[1]);
        self::assertTrue($dispatched[1]->passwordDiscarded());
        foreach ($dispatched as $event) {
            self::assertNotInstanceOf(UserPasswordChanged::class, $event);
        }
    }

    // ---- refuse an unverified provider email (AC-9) --------------------------------------------

    public function testAnUnverifiedProviderEmailCreatesNothingForAnUnknownAddress(): void
    {
        try {
            ($this->handler)($this->command('sub-unverified', 'nobody@example.com', emailVerified: false));
            self::fail('An unverified provider email must be refused.');
        } catch (OAuthEmailNotVerified) {
        }

        self::assertSame(0, $this->userCount());
        self::assertSame([], $this->events->dispatched());
    }

    public function testAnUnverifiedProviderEmailLinksNothingToAnExistingAccount(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('target@example.com')])->verified()->create();
        $before = $this->rowOf($existing->id()->toString());

        try {
            ($this->handler)($this->command('sub-unverified', 'target@example.com', emailVerified: false));
            self::fail('An unverified provider email must be refused.');
        } catch (OAuthEmailNotVerified) {
        }

        self::assertSame($before, $this->rowOf($existing->id()->toString()));
        self::assertSame([], $this->events->dispatched());
    }

    public function testAnUnverifiedProviderEmailDoesNotDiscardAnUnverifiedAccountsPassword(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('target@example.com')])->create();
        $before = $this->rowOf($existing->id()->toString());

        try {
            ($this->handler)($this->command('sub-unverified', 'target@example.com', emailVerified: false));
        } catch (OAuthEmailNotVerified) {
        }

        self::assertSame($before, $this->rowOf($existing->id()->toString()));
    }

    // ---- failure contract: malformed claims, I-28 ---------------------------------------------

    public function testAnEmailTheEmailValueObjectRejectsWritesNothing(): void
    {
        try {
            ($this->handler)($this->command('sub-bad-email', 'not-an-email'));
            self::fail('An invalid email must be refused.');
        } catch (InvalidEmail) {
        }

        self::assertSame(0, $this->userCount());
        self::assertSame([], $this->events->dispatched());
    }

    public function testAnEmptySubjectIsRefusedBeforeAnythingIsLookedUp(): void
    {
        $this->expectException(InvalidOAuthIdentity::class);

        ($this->handler)($this->command('', 'someone@example.com'));
    }

    public function testASubjectOver255CharactersIsRefusedAndWritesNothing(): void
    {
        try {
            ($this->handler)($this->command(str_repeat('a', 256), 'someone@example.com'));
            self::fail('An over-long subject must be refused.');
        } catch (InvalidOAuthIdentity) {
        }

        self::assertSame(0, $this->userCount());
    }

    public function testAMatchedAccountThatHoldsADifferentGoogleSubjectIsRefusedAndNothingChanges(): void
    {
        $existing = UserFactory::new(['email' => Email::fromString('two-google@example.com')])->provisionedViaGoogle('sub-first')->create();
        $before = $this->rowOf($existing->id()->toString());

        try {
            ($this->handler)($this->command('sub-second', 'two-google@example.com'));
            self::fail('A second Google subject must be refused.');
        } catch (OAuthProviderAlreadyLinked) {
        }

        self::assertSame($before, $this->rowOf($existing->id()->toString()));
        self::assertSame(1, $this->userCount());
        self::assertSame([], $this->events->dispatched());
    }

    // ---- the provision race (failure contract) -------------------------------------------------

    public function testTheLoserOfAProvisionRaceGetsEmailAlreadyRegisteredAndDispatchesNothing(): void
    {
        UserFactory::new(['email' => Email::fromString('raced@example.com')])->provisionedViaGoogle('sub-winner')->create();
        $loser = new SignInWithOAuthHandler(new StaleEmailLookupUserRepository($this->users), $this->clock, $this->events);

        try {
            $loser($this->command('sub-loser', 'raced@example.com'));
            self::fail('The unique index must refuse the second provisioning.');
        } catch (EmailAlreadyRegistered) {
        }

        self::assertSame([], $this->events->dispatched());
    }

    public function testRetryingAfterALostProvisionRaceTakesTheLinkPath(): void
    {
        $winner = UserFactory::new(['email' => Email::fromString('raced@example.com')])->verified()->create();
        $loser = new SignInWithOAuthHandler(new StaleEmailLookupUserRepository($this->users), $this->clock, $this->events);

        try {
            $loser($this->command('sub-loser', 'raced@example.com'));
        } catch (EmailAlreadyRegistered) {
        }

        // A failed flush closes the EntityManager; the next request gets a fresh one.
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $registry->resetManager();

        $retry = new SignInWithOAuthHandler($this->freshUsers(), $this->clock, $this->events);
        $email = $retry($this->command('sub-loser', 'raced@example.com'));

        self::assertSame('raced@example.com', $email->toString());
        self::assertSame(1, $this->userCount(), 'The retry links; it does not provision a second user.');
        $reloaded = $this->freshUsers()->findById($winner->id());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasOAuthIdentity(OAuthIdentity::of(OAuthProvider::Google, 'sub-loser')));
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function command(string $subject, string $email, bool $emailVerified = true): SignInWithOAuth
    {
        return new SignInWithOAuth(OAuthProvider::Google->value, $subject, $email, $emailVerified);
    }

    private function freshUsers(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private function userCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_user');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_user WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByEmail(string $email): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM identity_user WHERE email = :e', ['e' => $email]);
        self::assertIsArray($row, 'Expected a row for '.$email);

        return $row;
    }
}
