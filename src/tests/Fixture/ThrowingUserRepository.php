<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;

/**
 * A `UserRepository` whose every method throws, for proving what happens when a request dies in a
 * place no controller catches (a dependency down, not a domain refusal). Swap it in by the CONCRETE
 * adapter's service id, with `disableReboot()` first (CLAUDE.md, adapter-swap footgun).
 */
final class ThrowingUserRepository implements UserRepository
{
    public function nextIdentity(): UserId
    {
        throw $this->down();
    }

    public function save(User $user): void
    {
        throw $this->down();
    }

    public function findById(UserId $id): ?User
    {
        throw $this->down();
    }

    public function findByEmail(Email $email): ?User
    {
        throw $this->down();
    }

    public function existsByEmail(Email $email): bool
    {
        throw $this->down();
    }

    private function down(): \RuntimeException
    {
        return new \RuntimeException('user repository is down');
    }
}
