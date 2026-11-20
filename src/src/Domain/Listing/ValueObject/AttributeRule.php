<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeOptionId;
use App\Domain\Listing\Exception\InvalidAttributeValue;

/**
 * One entry of a category's attribute schema, in Listing's words: an attribute id, its kind, whether
 * it is required, and what a value must meet — for a choice its option ids, for a number its optional
 * bounds. No labels: no Domain code shows anything (ADR-0020 decision 3). Part of the `AttributeSchema` fact,
 * so only true inside `Categories::holdSteady()`.
 *
 * `read()` is L-9 for one attribute: it turns the seller's text into a value or refuses it. Parsing
 * lives here, next to the fact it needs — "42" is a fine String count and nonsense for Orientation —
 * the `Price::fromDecimal(string, Currency)` situation.
 */
final readonly class AttributeRule
{
    /**
     * Whole numbers fit a PHP int64 (and the `bigint` column) with room to spare at 18 digits, so
     * `(int)` never overflows and nothing is ever parsed through a float.
     */
    public const int NUMBER_MAX_DIGITS = 18;

    /**
     * ASCII digits only, an optional leading `-`, nothing else: no `+`, no separators, no exponent,
     * no decimals, no other script's digits ("٧" — `\d` under `u` would accept it). One unambiguous
     * shape, said in the error message — the `Price` reasoning. No `u` flag, so it never needs valid
     * UTF-8 to answer; `D` so a trailing "\n" is not swallowed by `$`.
     */
    private const string WHOLE_NUMBER = '/^-?[0-9]{1,'.self::NUMBER_MAX_DIGITS.'}$/D';

    /**
     * @param array<string, AttributeOptionId> $optionIds keyed by id: a Brand list has thousands
     */
    private function __construct(
        private AttributeId $attributeId,
        private AttributeKind $kind,
        private bool $required,
        private array $optionIds = [],
        private ?int $min = null,
        private ?int $max = null,
    ) {
    }

    /**
     * @param list<AttributeOptionId> $optionIds every option of the attribute — of **this**
     *                                           attribute only, so another one's option is refused
     */
    public static function choice(AttributeId $attributeId, array $optionIds, bool $required): self
    {
        $byId = [];

        foreach ($optionIds as $optionId) {
            $byId[$optionId->toString()] = $optionId;
        }

        return new self($attributeId, AttributeKind::Choice, $required, optionIds: $byId);
    }

    /** Both bounds inclusive, each optional. */
    public static function number(AttributeId $attributeId, ?int $min, ?int $max, bool $required): self
    {
        return new self($attributeId, AttributeKind::Number, $required, min: $min, max: $max);
    }

    public static function boolean(AttributeId $attributeId, bool $required): self
    {
        return new self($attributeId, AttributeKind::Boolean, $required);
    }

    public static function text(AttributeId $attributeId, bool $required): self
    {
        return new self($attributeId, AttributeKind::Text, $required);
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function kind(): AttributeKind
    {
        return $this->kind;
    }

    /**
     * Whether a **public** listing must have a value for this attribute (L-13). Never checked by
     * `read()`: a draft may leave it blank (L-11). Every named constructor takes it with no default
     * (plan D4), so a forgotten flag fails to compile instead of silently never biting.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Trims; empty means "no value" (null) — a draft may leave any detail blank (L-11). Otherwise
     * the value this rule accepts, or a refusal naming the attribute.
     *
     * - choice: one of this attribute's option ids;
     * - number: `WHOLE_NUMBER`, then within the bounds (inclusive) **current at this write** — so a
     *   stored value outside narrowed bounds is refused the next time it is submitted (Q7);
     * - boolean: exactly `yes` or `no` (what the form's select posts — anything else is tampering);
     * - text: `AttributeValue::ofText()`'s rule.
     *
     * @throws InvalidAttributeValue
     */
    public function read(string $raw): ?AttributeValue
    {
        $trimmed = trim($raw);

        if ('' === $trimmed) {
            return null;
        }

        return match ($this->kind) {
            AttributeKind::Choice => $this->readOption($trimmed),
            AttributeKind::Number => $this->readNumber($trimmed),
            AttributeKind::Boolean => $this->readBoolean($trimmed),
            AttributeKind::Text => AttributeValue::ofText($this->attributeId, $trimmed),
        };
    }

    private function readOption(string $trimmed): AttributeValue
    {
        try {
            $optionId = AttributeOptionId::fromString($trimmed);
        } catch (InvalidAttributeOptionId) {
            throw InvalidAttributeValue::notAnOption($this->attributeId);
        }

        if (!isset($this->optionIds[$optionId->toString()])) {
            throw InvalidAttributeValue::notAnOption($this->attributeId);
        }

        return AttributeValue::ofOption($this->attributeId, $optionId);
    }

    private function readNumber(string $trimmed): AttributeValue
    {
        if (1 !== preg_match(self::WHOLE_NUMBER, $trimmed)) {
            throw InvalidAttributeValue::notAWholeNumber($this->attributeId);
        }

        $number = (int) $trimmed;

        if ((null !== $this->min && $number < $this->min) || (null !== $this->max && $number > $this->max)) {
            throw InvalidAttributeValue::outOfRange($this->attributeId, $this->min, $this->max);
        }

        return AttributeValue::ofNumber($this->attributeId, $number);
    }

    private function readBoolean(string $trimmed): AttributeValue
    {
        return match ($trimmed) {
            'yes' => AttributeValue::ofBoolean($this->attributeId, true),
            'no' => AttributeValue::ofBoolean($this->attributeId, false),
            default => throw InvalidAttributeValue::notYesOrNo($this->attributeId),
        };
    }
}
