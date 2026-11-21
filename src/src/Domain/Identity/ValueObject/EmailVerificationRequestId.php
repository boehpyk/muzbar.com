<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidEmailVerificationRequestId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of an EmailVerificationRequest, as a value. The shape (format, no `generate()`, why a
 * trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class EmailVerificationRequestId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidEmailVerificationRequestId
    {
        return InvalidEmailVerificationRequestId::malformed($value);
    }
}
