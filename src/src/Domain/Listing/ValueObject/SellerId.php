<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidSellerId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The seller of a listing (L-1): Listing's name for an id another context owns — an `Identity` user
 * id. The Listing context says **seller**, not user, and never imports `UserId`
 * (ListingIsolationTest; ADR-0019 decision 2). Only Infrastructure translates between the two.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class SellerId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidSellerId
    {
        return InvalidSellerId::malformed($value);
    }
}
