<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidListingTitle;

/**
 * What a seller calls the gear ("Fender Precision V"). Invariant L-3: 3–120 characters after
 * trimming, counted with `mb_strlen` — sellers write Cyrillic as often as Latin, so 120 must mean
 * 120 letters, not 120 bytes.
 */
final readonly class ListingTitle
{
    public const int MIN_LENGTH = 3;

    /** Also the width of the `title` column. */
    public const int MAX_LENGTH = 120;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw InvalidListingTitle::length($length);
        }

        // Also rejects invalid UTF-8 (`preg_match` returns false, not 0, under `u`). Both matter at
        // rest: pdo_pgsql truncates a string at NUL, and invalid UTF-8 is SQLSTATE 22021 — so a
        // value accepted here would be stored shorter than L-3 allows, or not at all. CategoryName's rule.
        if (0 !== preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidListingTitle::containsControlCharacters();
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
