<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

final class InvalidEmailVerificationRequestId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid email verification request id.', $value));
    }
}
