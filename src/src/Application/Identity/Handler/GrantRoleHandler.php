<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\GrantRole;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\Role;

/**
 * Gives an account a role — the first caller `User::grantRole()` has had.
 *
 * Idempotent because the aggregate is (I-3): granting a held role changes nothing. No event: the
 * aggregate records none for a role change, and nothing would listen.
 */
final readonly class GrantRoleHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @throws \App\Domain\Identity\Exception\InvalidEmail
     * @throws UserNotFound
     * @throws \ValueError                                 for a role that is not a `Role` — the console adapter lists the valid ones
     */
    public function __invoke(GrantRole $command): void
    {
        $email = Email::fromString($command->email);
        $user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);

        $user->grantRole(Role::from($command->role));

        $this->users->save($user);
    }
}
