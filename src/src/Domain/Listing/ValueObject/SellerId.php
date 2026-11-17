<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidSellerId;

/**
 * The seller of a listing (L-1): Listing's name for an id another context owns — an `Identity` user
 * id. The Listing context says **seller**, not user, and never imports `UserId`
 * (ListingIsolationTest; ADR-0019 decision 2). Only Infrastructure translates between the two.
 *
 * A deliberate copy of the UUID id shape (`CategoryId`, `UserId`): ADR-0009's proposed trait
 * refactor is its own slice (listing-draft plan Risk 4), not scope creep here. There is no
 * `generate()`: minting is a vendor choice and belongs to a repository's `nextIdentity()`.
 */
final readonly class SellerId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD'; // D: a bare `$` also matches before a trailing "\n"

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidSellerId::malformed($value);
        }

        // Lower-cased so that two ids differing only in hex case compare equal.
        return new self(mb_strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
