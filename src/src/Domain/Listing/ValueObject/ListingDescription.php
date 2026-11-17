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
