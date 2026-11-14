<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategorySlug;

/**
 * The URL-safe key of a category, unique among its siblings (`electric`, `5-string-basses`).
 * Invariant C-2: `^[a-z0-9]+(?:-[a-z0-9]+)*$`, 1–64 characters, stored trimmed and lower-cased.
 *
 * The Domain **validates** slugs; it never **derives** one. Turning "Электрогитары" into
 * `elektrogitary` needs a transliterator, which is a vendor (Symfony's `AsciiSlugger`), so it
 * happens at the Infrastructure boundary (plan D11). What reaches this class is either something
 * the admin typed or something the slugger produced, and both are held to the same rule.
 *
 * Sibling uniqueness (C-3) is *not* this class's job — a value cannot see its siblings. That is a
 * handler pre-check under the tree lock plus two partial unique indexes.
 *
 * Slugs are editable. Nothing may reference a category by slug; persist `CategoryId` instead.
 */
final readonly class CategorySlug
{
    /** Also the width of the `slug` column. */
    public const int MAX_LENGTH = 64;

    private const string FORMAT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        // `strtolower`, not `mb_strtolower`: anything outside ASCII is refused by the format
        // below anyway, and lower-casing it first would only change which bytes get refused.
        $normalised = strtolower(trim($value));

        if (1 !== preg_match(self::FORMAT, $normalised)) {
            throw InvalidCategorySlug::malformed($normalised);
        }

        // The format guarantees ASCII, so `strlen` counts characters here.
        $length = \strlen($normalised);

        if ($length > self::MAX_LENGTH) {
            throw InvalidCategorySlug::tooLong($length);
        }

        return new self($normalised);
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
