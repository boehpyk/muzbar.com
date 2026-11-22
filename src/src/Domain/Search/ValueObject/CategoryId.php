<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

use App\Domain\Search\Exception\InvalidCategoryId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * Search's name for the category being browsed — a `Catalog` category id. A local copy: never import another context's id
 * (ADR-0019 decision 2, ADR-0023 fence point 4); the adapter translates through `toString()`.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class CategoryId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidCategoryId
    {
        return InvalidCategoryId::malformed($value);
    }
}
