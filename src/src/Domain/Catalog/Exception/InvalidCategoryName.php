<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryName;

final class InvalidCategoryName extends \DomainException
{
    public static function empty(): self
    {
        return new self('A category name may not be empty.');
    }

    public static function tooLong(int $length): self
    {
        return new self(\sprintf('A category name may not exceed %d characters, got %d.', CategoryName::MAX_LENGTH, $length));
    }

    public static function containsControlCharacters(): self
    {
        return new self('A category name may not contain line breaks or other control characters.');
    }
}
