<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeLabel;

/** C-9: an attribute or option label is 1–100 characters, without control characters. */
final class InvalidAttributeLabel extends \DomainException
{
    public static function empty(): self
    {
        return new self('A label may not be empty.');
    }

    public static function tooLong(int $length): self
    {
        return new self(\sprintf('A label may not exceed %d characters, got %d.', AttributeLabel::MAX_LENGTH, $length));
    }

    public static function containsControlCharacters(): self
    {
        return new self('A label may not contain line breaks or other control characters.');
    }
}
