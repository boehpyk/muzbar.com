<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategorySlug;

final class InvalidCategorySlug extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid category slug: use lower-case letters, digits and single hyphens.', $value));
    }

    public static function tooLong(int $length): self
    {
        return new self(\sprintf('A category slug may not exceed %d characters, got %d.', CategorySlug::MAX_LENGTH, $length));
    }
}
