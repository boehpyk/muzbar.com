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
     * The case-folded form: "LEFT-Handed" and "left-handed" share one key. **Simple** case mapping,
     * not `mb_strtolower`'s full mapping: full mapping turns "İ" (U+0130) into two code points, so a
     * 100-character label could produce a 101+-character key and overflow `label_key varchar(100)`.
     * Simple mapping is one code point per code point, so a key always fits beside its label.
     */
    public function key(): string
    {
        return mb_convert_case($this->value, \MB_CASE_LOWER_SIMPLE);
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
