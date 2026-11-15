<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\Unit;

/** C-16: a unit is 1–16 characters, without control characters. */
final class InvalidUnit extends \DomainException
{
    public static function length(int $length): self
    {
        return new self(\sprintf('A unit must be 1–%d characters, got %d.', Unit::MAX_LENGTH, $length));
    }

    public static function containsControlCharacters(): self
    {
        return new self('A unit may not contain line breaks or other control characters.');
    }
}
