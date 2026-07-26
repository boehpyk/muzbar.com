<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\VerifyUserEmail;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Records that an address has been proven reachable.
 *
 * This handler is the reason the verification use case is not dead code in slice 1: the console
 * command `muzbar:identity:verify-email` calls it today, and `identity-email-verification` will
 * add a *second* adapter — the signed-link controller — over this same handler, deleting nothing.
 * One use case, two adapters, is hexagonal architecture demonstrating itself.
 *
 * Note what is absent: there is no "if already verified" branch here. The idempotency lives in
 * `User::verifyEmail()`, which is where it belongs — a rule about the aggregate's state, held by
 * the aggregate, so every present and future caller inherits it rather than re-implementing it.
 */
final readonly class VerifyUserEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * Same transaction boundary as registration: `save()` commits, then events publish, so a
     * listener never sees a fact that was rolled back.
     *
     * On a repeat call the aggregate records nothing, so `releaseEvents()` yields an empty list
     * and `dispatch()` is a no-op — the variadic port signature is what lets that stay
     * branch-free here.
     *
     * @throws \App\Domain\Identity\Exception\InvalidEmail
     * @throws UserNotFound
     */
    public function __invoke(VerifyUserEmail $command): void
    {
        $email = Email::fromString($command->email);
        $user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);

        $user->verifyEmail($this->clock->now());

        $this->users->save($user);
        $this->events->dispatch(...$user->releaseEvents());
    }
}
