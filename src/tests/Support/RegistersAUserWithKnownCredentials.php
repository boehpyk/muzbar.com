<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\PlainPassword;
use App\Tests\Factory\UserFactory;

/**
 * Login tests need a user whose password the *real* firewall can verify — `UserFactory`'s
 * default `passwordHash` is an arbitrary opaque value on purpose (see `HashedPasswordTest`), so
 * it cannot log anyone in. This hashes a known plaintext through the actual `PasswordHasher` port
 * (the container's `SymfonyPasswordHasher`, the same one `security.yaml` configures the firewall
 * to verify against), then hands the result to the factory — no production code changed, no
 * service faked, just the real port used the way `RegisterUserHandler` uses it.
 */
trait RegistersAUserWithKnownCredentials
{
    private function registerUserWithKnownCredentials(string $email, string $plainPassword, bool $verified = false): User
    {
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $hash = $hasher->hash(PlainPassword::fromString($plainPassword));

        $factory = UserFactory::new(['email' => Email::fromString($email), 'passwordHash' => $hash]);

        return $verified ? $factory->verified()->create() : $factory->create();
    }
}
