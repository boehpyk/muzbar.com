<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The attribute a value answers (L-8, L-9): Listing's name for an id another context owns — a
 * `Catalog` attribute id. Never import Catalog's `AttributeId` (ListingIsolationTest; ADR-0019
 * decision 2, ADR-0020 decision 8); the cross-context adapters translate through `toString()`.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class AttributeId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidAttributeId
    {
        return InvalidAttributeId::malformed($value);
    }
}
