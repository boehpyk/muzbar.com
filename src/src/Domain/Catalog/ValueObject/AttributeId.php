<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeId;

/**
 * The identity of an Attribute, as a value — what an option, and later a listing value, holds to
 * refer to one. Never the code: codes are editable (C-10/C-11).
 *
 * Another written-out copy of the `CategoryId` shape (plan D12): the shared-UUID trait stays its own
 * refactor (owner, Q9; ADR-0009's 2026-11-14 amendment). No `generate()`: minting belongs to the
 * repository's `nextIdentity()`. The format check is version-agnostic on purpose.
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
