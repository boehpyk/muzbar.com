<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidCategoryId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The category a listing is filed under (L-2): Listing's name for an id another context owns — a
 * `Catalog` category id. Never import Catalog's `CategoryId` (ListingIsolationTest; ADR-0019
 * decision 2); the cross-context adapters translate through `toString()`.
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
