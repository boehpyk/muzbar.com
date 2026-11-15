<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryName;

/**
 * What an admin calls a category ("Bass Guitars"). Invariant C-1: 1–100 characters after trimming,
 * no control characters.
 *
 * Counted with `mb_strlen`, because the people naming categories write Cyrillic as often as Latin
 * and 100 characters must mean 100 letters, not 100 bytes. Control characters (`\p{Cc}`, which
 * includes line breaks and tabs) are refused rather than stripped: a name is shown on one line in
 * every place it will ever appear, and silently rewriting what the admin typed would be a surprise.
 *
 * Names are **not** unique among siblings — slugs disambiguate (non-goal in the spec).
 */
final readonly class CategoryName
{
    /** Also the width of the `name` column. */
    public const int MAX_LENGTH = 100;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if (0 === $length) {
            throw InvalidCategoryName::empty();
        }

        if ($length > self::MAX_LENGTH) {
            throw InvalidCategoryName::tooLong($length);
        }

        // Also rejects invalid UTF-8: with the `u` modifier `preg_match` returns false, not 0.
        if (0 !== preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidCategoryName::containsControlCharacters();
        }

        return new self($trimmed);
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
