<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeId;

/**
 * The attribute a value answers (L-8, L-9): Listing's name for an id another context owns — a
 * `Catalog` attribute id. Never import Catalog's `AttributeId` (ListingIsolationTest; ADR-0019
 * decision 2, ADR-0020 decision 8); the cross-context adapters translate through `toString()`.
 *
 * A deliberate copy of the UUID id shape — the tenth. ADR-0009's trait refactor is its own slice,
 * worth scheduling before `listing-publish`. No `generate()`: Listing never mints one.
 */
final readonly class AttributeId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD'; // D: a bare `$` also matches before a trailing "\n"

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidAttributeId::malformed($value);
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
