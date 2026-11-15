<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeCode;

/** C-10: lower-case letters, digits and single hyphens, at most 64 characters. */
final class InvalidAttributeCode extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid attribute code: use lower-case letters, digits and single hyphens.', $value));
    }

    public static function tooLong(int $length): self
    {
        return new self(\sprintf('An attribute code may not exceed %d characters, got %d.', AttributeCode::MAX_LENGTH, $length));
    }
}
