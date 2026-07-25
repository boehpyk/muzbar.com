<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\PlainPassword;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * The single place in the codebase where a plaintext password is unwrapped.
 *
 * WHY THE *FACTORY* AND NOT `UserPasswordHasherInterface`. The obvious choice is the one that does
 * not work: `UserPasswordHasherInterface::hashPassword()` takes a `PasswordAuthenticatedUserInterface`
 * so it can pick the algorithm configured for that user's class — and at registration time there is
 * no user yet. That is the whole point of the ordering: the hash has to exist *before*
 * `User::register()` can be called, because the aggregate accepts only a `HashedPassword`.
 * Inventing a throwaway `SecurityUser` to satisfy the signature would be a lie told to a type.
 *
 * So we ask the factory directly, and we ask it **for `SecurityUser::class`** rather than for some
 * default. `security.yaml` keys its hasher on `PasswordAuthenticatedUserInterface`, which
 * `SecurityUser` implements, so the algorithm resolved here is provably the same one the firewall
 * will verify with on the next login. Passing a different class name — or omitting this detail and
 * letting a future `password_hashers` entry diverge — produces accounts that can register and can
 * never log in, and the failure surfaces nowhere near its cause.
 */
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function hash(PlainPassword $password): HashedPassword
    {
        // `reveal()` is named to be conspicuous, and this is the one call site that should ever
        // appear in a grep for it. The revealed string is not assigned to a variable, so it never
        // becomes something a `dump()` or a stack frame can pick up.
        return HashedPassword::fromString(
            $this->hasherFactory->getPasswordHasher(SecurityUser::class)->hash($password->reveal()),
        );
    }
}
