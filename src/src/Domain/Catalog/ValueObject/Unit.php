<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidUnit;

/**
 * A short display label for a number attribute ("mm", "kg", "W", "″"). Invariant C-16: 1–16
 * characters after trimming, no control characters.
 *
 * Display and meaning only: there is no conversion, and two units are never compared for
 * compatibility. A unit is not a currency.
 */
final readonly class Unit
{
    public const int MAX_LENGTH = 16;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if (0 === $length || $length > self::MAX_LENGTH) {
            throw InvalidUnit::length($length);
        }

        // Also rejects invalid UTF-8: with the `u` modifier `preg_match` returns false, not 0.
        if (0 !== preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidUnit::containsControlCharacters();
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
