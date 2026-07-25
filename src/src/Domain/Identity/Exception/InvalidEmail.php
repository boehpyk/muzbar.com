<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\Email;

final class InvalidEmail extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid email address.', $value));
    }

    public static function tooLong(int $length): self
    {
        return new self(\sprintf('An email address may not exceed %d characters, got %d.', Email::MAX_LENGTH, $length));
    }
}
