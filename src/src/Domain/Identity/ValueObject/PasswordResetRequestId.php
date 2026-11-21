<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidPasswordResetRequestId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of a PasswordResetRequest, as a value. The shape (format, no `generate()`, why a
 * trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class PasswordResetRequestId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidPasswordResetRequestId
    {
        return InvalidPasswordResetRequestId::malformed($value);
    }
}
