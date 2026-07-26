<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\RegisterUser;
use App\Application\Identity\Handler\RegisterUserHandler;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Exception\WeakPassword;
use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `RegisterUserHandler` against the real `muzbar_test` database (DAMA
 * rollback), with a frozen `Clock` and a spy `DomainEventDispatcher` standing in for the real
 * ones so the handler's four collaborators are all observable.
 */
final class RegisterUserHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private RegisterUserHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-25T12:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();

        $this->handler = new RegisterUserHandler($this->users, $hasher, $this->clock, $this->events);
    }

    /**
     * AC-2 / AC-27: the happy path persists the user, returns its id, and dispatches exactly one
     * `UserRegistered` carrying that id and email.
     */
    public function testHappyPathPersistsTheUserAndDispatchesExactlyOneUserRegistered(): void
    {
        $id = ($this->handler)(new RegisterUser('New.User@Example.com', 'a-strong-password-1'));

        $found = $this->users->findById($id);
        self::assertNotNull($found);
        self::assertSame('new.user@example.com', $found->email()->toString());

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserRegistered::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($id));
        self::assertTrue($dispatched[0]->email()->equals(Email::fromString('new.user@example.com')));
    }

    /**
     * The `Clock` port, not the wall clock, is provably the source of `registeredAt`: a frozen
     * instant far from "now" ends up on the stored row.
     */
    public function testRegisteredAtComesFromTheClockPortNotTheWallClock(): void
    {
        $id = ($this->handler)(new RegisterUser('clocked@example.com', 'a-strong-password-1'));

        $found = $this->users->findById($id);

        self::assertNotNull($found);
        self::assertEquals(new \DateTimeImmutable('2026-07-25T12:00:00+00:00'), $found->registeredAt());
    }

    /**
     * AC-4 / AC-27 (handler layer): a duplicate email throws `EmailAlreadyRegistered` and
     * dispatches nothing.
     */
    public function testDuplicateEmailThrowsAndDispatchesNoEvent(): void
    {
        ($this->handler)(new RegisterUser('taken@example.com', 'a-strong-password-1'));

        $freshEvents = new SpyDomainEventDispatcher();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $handler = new RegisterUserHandler($this->users, $hasher, $this->clock, $freshEvents);

        try {
            $handler(new RegisterUser('TAKEN@Example.com ', 'another-strong-password-2'));
            self::fail('Expected EmailAlreadyRegistered to be thrown.');
        } catch (EmailAlreadyRegistered) {
            // expected
        }

        self::assertSame([], $freshEvents->dispatched());
    }

    /**
     * AC-5 (handler layer): a weak password throws before anything is persisted or dispatched.
     */
    public function testWeakPasswordThrowsAndPersistsNothing(): void
    {
        $this->expectException(WeakPassword::class);

        try {
            ($this->handler)(new RegisterUser('weak-password@example.com', 'short'));
        } finally {
            self::assertFalse($this->users->existsByEmail(Email::fromString('weak-password@example.com')));
            self::assertSame([], $this->events->dispatched());
        }
    }
}
