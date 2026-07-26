<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\VerifyUserEmail;
use App\Application\Identity\Handler\VerifyUserEmailHandler;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `VerifyUserEmailHandler` against the real `muzbar_test` database (DAMA
 * rollback), with a frozen `Clock` and a spy `DomainEventDispatcher`.
 *
 * Foundry's PHPUnit extension (registered globally in phpunit.dist.xml) boots Foundry's
 * configuration for every test in the suite, so no `Factories` trait is needed here — adding it
 * on top would trigger Foundry's own "trait is deprecated, the extension already does this"
 * deprecation, which `failOnDeprecation="true"` would turn into a failure.
 */
final class VerifyUserEmailHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private VerifyUserEmailHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-26T09:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();
        $this->handler = new VerifyUserEmailHandler($this->users, $this->clock, $this->events);
    }

    /**
     * AC-25 / AC-27: verifying an unverified user sets the timestamp and dispatches exactly one
     * `UserEmailVerified`.
     */
    public function testVerifyingAnUnverifiedUserSetsTheTimestampAndDispatchesOneEvent(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('to-verify@example.com')]);

        ($this->handler)(new VerifyUserEmail('to-verify@example.com'));

        $found = $this->users->findById($user->id());
        self::assertNotNull($found);
        self::assertTrue($found->isEmailVerified());
        self::assertEquals(new \DateTimeImmutable('2026-07-26T09:00:00+00:00'), $found->emailVerifiedAt());

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserEmailVerified::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($user->id()));
    }

    /**
     * AC-25: a second verification is idempotent — the handler dispatches nothing and the
     * stored timestamp does not move, even though the clock has advanced in the meantime (a real
     * "unverify" bug would show up here as a moved timestamp).
     */
    public function testVerifyingAnAlreadyVerifiedUserIsIdempotentAndDispatchesNothing(): void
    {
        UserFactory::createOne(['email' => Email::fromString('already-verified@example.com')]);
        ($this->handler)(new VerifyUserEmail('already-verified@example.com'));

        $firstTimestamp = $this->users->findByEmail(Email::fromString('already-verified@example.com'))?->emailVerifiedAt();
        self::assertNotNull($firstTimestamp);

        $laterClock = new FrozenClock(new \DateTimeImmutable('2026-08-01T00:00:00+00:00'));
        $secondEvents = new SpyDomainEventDispatcher();
        $handler = new VerifyUserEmailHandler($this->users, $laterClock, $secondEvents);

        $handler(new VerifyUserEmail('already-verified@example.com'));

        $found = $this->users->findByEmail(Email::fromString('already-verified@example.com'));
        self::assertNotNull($found);
        self::assertEquals($firstTimestamp, $found->emailVerifiedAt());
        self::assertSame([], $secondEvents->dispatched());
    }

    /**
     * AC-26: an unknown email throws `UserNotFound` and mutates nothing (there is nothing to
     * mutate — the failure happens before any aggregate is loaded).
     */
    public function testUnknownEmailThrowsUserNotFound(): void
    {
        $this->expectException(UserNotFound::class);

        ($this->handler)(new VerifyUserEmail('nobody-here@example.com'));
    }
}
