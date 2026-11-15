<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\RevokeRole;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\Role;

/**
 * Takes a role away from an account. Refusing to revoke `ROLE_USER` is the aggregate's rule
 * (`CannotRevokeBaseRole`, I-3), not this handler's — it throws before anything is saved.
 */
final readonly class RevokeRoleHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @throws \App\Domain\Identity\Exception\InvalidEmail
     * @throws UserNotFound
     * @throws \App\Domain\Identity\Exception\CannotRevokeBaseRole
     * @throws \ValueError                                         for a role that is not a `Role` — the console adapter lists the valid ones
     */
    public function __invoke(RevokeRole $command): void
    {
        $email = Email::fromString($command->email);
        $user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);

        $user->revokeRole(Role::from($command->role));

        $this->users->save($user);
    }
}
