<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeLabel;

/**
 * What an admin, and later a user, reads: an attribute's label ("String count") or an option's
 * ("Left-handed"). Invariant C-9: 1–100 characters after trimming, no control characters — the
 * `CategoryName` rules, for the same reasons (Cyrillic counts as letters, not bytes; a label is shown
 * on one line everywhere).
 *
 * `key()` is **the single definition of "the same label"** for C-17 (option labels unique within an
 * attribute, case-insensitively). The handler's pre-check and the stored `label_key` column both come
 * from it, so PHP's case-folding is the only one ever used — there is no PHP-versus-Postgres collation
 * to drift apart (plan D3, ADR-0017 decision 4).
 */
final readonly class AttributeLabel
{
    /** Also the width of the `label` and `label_key` columns. */
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
            throw InvalidAttributeLabel::empty();
        }

        if ($length > self::MAX_LENGTH) {
            throw InvalidAttributeLabel::tooLong($length);
        }

        // Also rejects invalid UTF-8: with the `u` modifier `preg_match` returns false, not 0.
        if (0 !== preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidAttributeLabel::containsControlCharacters();
        }

        return new self($trimmed);
    }

    /**
     * The case-folded form: "LEFT-Handed" and "left-handed" share one key. Lower-casing never makes
     * a string longer in characters, so a key fits the same `varchar(100)` as its label.
     */
    public function key(): string
    {
        return mb_strtolower($this->value);
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
