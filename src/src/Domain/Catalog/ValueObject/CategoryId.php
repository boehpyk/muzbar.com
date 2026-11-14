<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryId;

/**
 * The identity of a Category, as a value — and the only thing any other aggregate, in any later
 * slice, may hold to refer to one (C-8). Never a slug, never a path: both are editable.
 *
 * A deliberate copy of `UserId`'s shape (plan D8). ADR-0009's 2026-07-29 amendment said to revisit
 * the duplicated UUID value objects "at the first aggregate id outside `Identity`, and only if the
 * extraction can preserve cross-type comparison as a compile-time error". This is that id, so the
 * trigger has fired and is answered on record: **still duplicated**. A trait would meet the
 * criterion — `self` inside a trait resolves to the using class, so `equals(self $other)` keeps
 * refusing a `UserId` — but extracting it means editing three Identity value objects, which is its
 * own small refactor rather than scope creep in a Catalog slice. A base class would not meet it:
 * `equals(parent $other)` would happily compare a category with a user.
 *
 * As with `UserId`, there is no `generate()`: minting an id is a vendor choice and belongs to
 * `CategoryRepository::nextIdentity()`. The format check is version-agnostic on purpose.
 */
final readonly class CategoryId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD'; // D: a bare `$` also matches before a trailing "\n"

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidCategoryId::malformed($value);
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
