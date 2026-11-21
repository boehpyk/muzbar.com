<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of a Listing, as a value — what anything outside the aggregate holds to refer to one
 * (ADR-0009 decision 4: an id, never the object).
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class ListingId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidListingId
    {
        return InvalidListingId::malformed($value);
    }
}
