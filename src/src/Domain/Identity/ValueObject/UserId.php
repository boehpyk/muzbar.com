<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidUserId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of a User, as a value. The shape (format, no `generate()`, why a trait) is
 * documented once, on {@see UuidIdShape}.
 */
final readonly class UserId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidUserId
    {
        return InvalidUserId::malformed($value);
    }
}
