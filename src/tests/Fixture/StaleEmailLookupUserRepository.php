<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\UserId;

/**
 * Delegates to a real `UserRepository` except that `findByEmail()` answers "nobody" — the view a
 * callback has when a concurrent first-time callback inserts the same address between its lookup
 * and its `save()`. The real repository then hits `uniq_identity_user_email`, which is the failure
 * contract's "provision race" row, reproduced deterministically in one process.
 */
final readonly class StaleEmailLookupUserRepository implements UserRepository
{
    public function __construct(private UserRepository $inner)
    {
    }

    public function nextIdentity(): UserId
    {
        return $this->inner->nextIdentity();
    }

    public function save(User $user): void
    {
        $this->inner->save($user);
    }

    public function findById(UserId $id): ?User
    {
        return $this->inner->findById($id);
    }

    public function findByEmail(Email $email): ?User
    {
        return null;
    }

    public function existsByEmail(Email $email): bool
    {
        return $this->inner->existsByEmail($email);
    }

    public function findByOAuthIdentity(OAuthIdentity $identity): ?User
    {
        return $this->inner->findByOAuthIdentity($identity);
    }
}
