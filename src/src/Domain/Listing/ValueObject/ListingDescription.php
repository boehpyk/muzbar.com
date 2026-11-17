<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidListingDescription;

/**
 * The seller's free text about the gear. Invariant L-4: 0–5000 characters after trimming — optional
 * even on a draft (plan Q4), so an empty description is `""`, never null. Line breaks are kept:
 * unlike a title, a description is shown as a paragraph.
 */
final readonly class ListingDescription
{
    /** Also the width of the `description` column. */
    public const int MAX_LENGTH = 5000;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if ($length > self::MAX_LENGTH) {
            throw InvalidListingDescription::tooLong($length);
        }

        // ListingTitle's rule, minus the line breaks and tabs a paragraph legitimately holds. Also
        // rejects invalid UTF-8; a NUL would otherwise silently cut the stored text short.
        if (0 !== preg_match('/[^\P{Cc}\t\n\r]/u', $trimmed)) {
            throw InvalidListingDescription::containsControlCharacters();
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
