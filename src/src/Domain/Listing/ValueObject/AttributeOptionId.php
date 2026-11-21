<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeOptionId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The option a choice value names (L-9, C-26): Listing's name for an id another context owns — a
 * `Catalog` attribute option id. Never import Catalog's `AttributeOptionId` (ListingIsolationTest; ADR-0019
 * decision 2, ADR-0020 decision 8); the cross-context adapters translate through `toString()`.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class AttributeOptionId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidAttributeOptionId
    {
        return InvalidAttributeOptionId::malformed($value);
    }
}
