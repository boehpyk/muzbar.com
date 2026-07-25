<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\RegisterUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\PlainPassword;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Orchestrates registration, and does no thinking of its own.
 *
 * A handler is deliberately boring: it turns primitives into value objects, loads or creates one
 * aggregate, calls one method on it, saves, and publishes. Every rule it appears to enforce
 * actually lives somewhere else — the password policy in `PlainPassword`, the address rules in
 * `Email`, the role seeding in `User::register()`. When a handler starts growing conditionals, the
 * usual diagnosis is that a domain concept is missing.
 *
 * All four collaborators are ports, so this class can be unit-tested with no database, no hashing
 * cost and a frozen clock — and so `registeredAt` is provably the `Clock`'s value rather than
 * whatever the wall clock said.
 */
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: one command, one transaction. `save()` owns persist-and-flush inside
     * the adapter, so this handler holds no Doctrine concept and nothing here can leave a
     * half-written user behind.
     *
     * Events are dispatched **after** a successful save, so no listener can ever observe a fact
     * that was rolled back. The knowingly accepted consequence is that dispatch sits outside the
     * transaction: a crash between flush and dispatch loses the event. That is acceptable while
     * nothing listens; when `identity-email-verification` makes a listener load-bearing, this is
     * the line that gets a transactional outbox.
     *
     * @throws \App\Domain\Identity\Exception\InvalidEmail
     * @throws \App\Domain\Identity\Exception\WeakPassword
     * @throws EmailAlreadyRegistered
     */
    public function __invoke(RegisterUser $command): UserId
    {
        $email = Email::fromString($command->email);

        // Loses a race by design — the unique index is the real guarantee (I-6). This pre-check
        // exists so the common case produces a field error instead of a caught database exception.
        if ($this->users->existsByEmail($email)) {
            throw EmailAlreadyRegistered::withEmail($email);
        }

        $plainPassword = PlainPassword::fromString($command->plainPassword);
        $passwordHash = $this->hasher->hash($plainPassword);

        $user = User::register(
            $this->users->nextIdentity(),
            $email,
            $passwordHash,
            $this->clock->now(),
        );

        $this->users->save($user);
        $this->events->dispatch(...$user->releaseEvents());

        return $user->id();
    }
}
