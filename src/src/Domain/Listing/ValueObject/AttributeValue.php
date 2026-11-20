<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;

/**
 * What one listing says for one attribute: an option, a whole number, yes/no or a short text —
 * exactly one of them, chosen by the kind (the row's `CHECK (num_nonnulls(…) = 1)` mirrors it).
 * A part of `Listing`, never a root (ADR-0020 decision 1): nothing outside the listing refers to it.
 *
 * Built from a seller's input only through `AttributeRule::read()`, which knows the attribute's
 * options and bounds; those are facts about the schema at the moment of the write, so they are not
 * re-checked here — a stored 6 stays valid after the bounds narrow to 7…12 (never retroactive,
 * D11.2). The **text** rule is different: it is about the value alone (one line, 1–200 characters,
 * no control characters, valid UTF-8), so it lives here and holds for a hydrated row too — pdo_pgsql
 * truncates at NUL, and a row that slipped past it would be stored as something else.
 *
 * Constructors are `of…()` because PHP cannot have a static and an instance method of one name, and
 * the readers (`number()`, `boolean()`, `text()`) are the ones callers read most.
 */
final readonly class AttributeValue
{
    /** Also the width of the `value_text` column. */
    public const int TEXT_MAX_LENGTH = 200;

    private function __construct(
        private AttributeId $attributeId,
        private AttributeKind $kind,
        private ?AttributeOptionId $optionId = null,
        private ?int $number = null,
        private ?bool $boolean = null,
        private ?string $text = null,
    ) {
    }

    public static function ofOption(AttributeId $attributeId, AttributeOptionId $optionId): self
    {
        return new self($attributeId, AttributeKind::Choice, optionId: $optionId);
    }

    public static function ofNumber(AttributeId $attributeId, int $number): self
    {
        return new self($attributeId, AttributeKind::Number, number: $number);
    }

    public static function ofBoolean(AttributeId $attributeId, bool $boolean): self
    {
        return new self($attributeId, AttributeKind::Boolean, boolean: $boolean);
    }

    /**
     * Trims, then refuses anything that is not one line of 1–200 characters (`mb_strlen`: a
     * Ukrainian seller's 200 letters, not 200 bytes) of valid UTF-8 without control characters.
     *
     * @throws InvalidAttributeValue
     */
    public static function ofText(AttributeId $attributeId, string $text): self
    {
        // Encoding first: every later check (`mb_strlen`, a `u` regex) is meaningless on invalid
        // UTF-8, and a `u` preg_match answers `false` there, not 0. SQLSTATE 22021 at rest.
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw InvalidAttributeValue::badText($attributeId);
        }

        $trimmed = trim($text);
        $length = mb_strlen($trimmed);

        // \p{Cc} covers "\n", "\t" and NUL: ListingTitle's rule, one line and nothing pdo_pgsql truncates.
        // ponytail: \p{Cf} (zero-width, bidi overrides) and U+2028/U+2029 still pass, as in every
        // free-text VO; widen all of them together to [\p{Cc}\p{Cf}\p{Zl}\p{Zp}], never this one alone.
        if ($length < 1 || $length > self::TEXT_MAX_LENGTH || 0 !== preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidAttributeValue::badText($attributeId);
        }

        return new self($attributeId, AttributeKind::Text, text: $trimmed);
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function kind(): AttributeKind
    {
        return $this->kind;
    }

    /** Non-null exactly for a choice. */
    public function optionId(): ?AttributeOptionId
    {
        return $this->optionId;
    }

    /** Non-null exactly for a number. */
    public function number(): ?int
    {
        return $this->number;
    }

    /** Non-null exactly for a boolean. */
    public function boolean(): ?bool
    {
        return $this->boolean;
    }

    /** Non-null exactly for a text. */
    public function text(): ?string
    {
        return $this->text;
    }

    public function equals(self $other): bool
    {
        return $this->attributeId->equals($other->attributeId)
            && $this->kind === $other->kind
            && $this->optionId?->toString() === $other->optionId?->toString()
            && $this->number === $other->number
            && $this->boolean === $other->boolean
            && $this->text === $other->text;
    }
}
