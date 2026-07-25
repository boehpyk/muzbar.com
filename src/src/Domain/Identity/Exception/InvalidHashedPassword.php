<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * Messages describe the *hash*, never a password — and never the hash's own value either, since
 * exception messages reach logs.
 */
final class InvalidHashedPassword extends \DomainException
{
    public static function empty(): self
    {
        return new self('A password hash may not be empty.');
    }

    public static function tooLong(int $maximum): self
    {
        return new self(\sprintf('A password hash may not exceed %d characters.', $maximum));
    }
}
