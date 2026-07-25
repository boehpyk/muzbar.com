<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;

/**
 * A use case asked for a User that does not exist.
 *
 * Distinct from Symfony's `UserNotFoundException`, which is an authentication concern: this one is
 * raised by a handler that needed a specific account and could not proceed without it. The login
 * flow must keep using the framework's exception, so that "no such email" and "wrong password"
 * stay indistinguishable to a visitor (AC-13).
 */
final class UserNotFound extends \DomainException
{
    public static function withEmail(Email $email): self
    {
        return new self(\sprintf('No user found with email "%s".', $email->toString()));
    }

    public static function withId(UserId $id): self
    {
        return new self(\sprintf('No user found with id "%s".', $id->toString()));
    }
}
