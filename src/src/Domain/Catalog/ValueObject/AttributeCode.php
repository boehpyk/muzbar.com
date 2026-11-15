<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeCode;

/**
 * The URL/API-safe key of an attribute (`string-count`). Invariant C-10: the `CategorySlug` format —
 * `^[a-z0-9]+(?:-[a-z0-9]+)*$`, 1–64 characters, stored trimmed and lower-cased.
 *
 * As with slugs, the Domain validates a code and never derives one: transliterating a label is the
 * Infrastructure's job (`CategorySlugDeriver`, plan D9).
 *
 * Uniqueness within the defining category (C-11) is not this class's job — a value cannot see other
 * attributes. Codes are editable, so nothing may reference an attribute by code; hold `AttributeId`.
 */
final readonly class AttributeCode
{
    /** Also the width of the `code` column. */
    public const int MAX_LENGTH = 64;

    private const string FORMAT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        // `strtolower`, not `mb_strtolower`: the format below refuses anything outside ASCII anyway.
        $normalised = strtolower(trim($value));

        if (1 !== preg_match(self::FORMAT, $normalised)) {
            throw InvalidAttributeCode::malformed($normalised);
        }

        // The format guarantees ASCII, so `strlen` counts characters here.
        $length = \strlen($normalised);

        if ($length > self::MAX_LENGTH) {
            throw InvalidAttributeCode::tooLong($length);
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
