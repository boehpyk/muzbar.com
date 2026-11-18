<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeValue;

/**
 * A seller's input for one attribute that the listing will not hold (L-9, L-10) — always about
 * **one** attribute, so the form can put the message on that field.
 *
 * The attribute is carried as its **raw key**, not only as an `AttributeId`: a tampered form can
 * post a key that is not an id at all, and that refusal ("not in force") still has to land on the
 * field it came from. `attributeId()` is the parsed view, null exactly for such a key.
 *
 * `outOfRange()` carries the bounds that were current at the write, because the message names them
 * ("from 4 to 12") and they may have been narrowed since the value was stored.
 */
final class InvalidAttributeValue extends \DomainException
{
    private function __construct(
        string $message,
        private readonly string $attributeKey,
        private readonly InvalidAttributeValueReason $reason,
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {
        parent::__construct($message);
    }

    public static function notAnOption(AttributeId $id): self
    {
        return new self(\sprintf('The value for attribute "%s" is not one of its options.', $id->toString()), $id->toString(), InvalidAttributeValueReason::NotAnOption);
    }

    public static function notAWholeNumber(AttributeId $id): self
    {
        return new self(\sprintf('The value for attribute "%s" is not a whole number.', $id->toString()), $id->toString(), InvalidAttributeValueReason::NotAWholeNumber);
    }

    public static function outOfRange(AttributeId $id, ?int $min, ?int $max): self
    {
        return new self(
            \sprintf('The value for attribute "%s" is outside %s…%s.', $id->toString(), $min ?? '', $max ?? ''),
            $id->toString(),
            InvalidAttributeValueReason::OutOfRange,
            $min,
            $max,
        );
    }

    public static function notYesOrNo(AttributeId $id): self
    {
        return new self(\sprintf('The value for attribute "%s" is neither "yes" nor "no".', $id->toString()), $id->toString(), InvalidAttributeValueReason::NotYesOrNo);
    }

    public static function badText(AttributeId $id): self
    {
        return new self(
            \sprintf('The value for attribute "%s" must be one line of 1 to %d characters, without control characters or invalid UTF-8.', $id->toString(), AttributeValue::TEXT_MAX_LENGTH),
            $id->toString(),
            InvalidAttributeValueReason::BadText,
        );
    }

    /** `$attributeKey` is raw on purpose: it may not be a well-formed id at all. */
    public static function notInForce(string $attributeKey): self
    {
        return new self(\sprintf('Attribute "%s" is not in force on the listing\'s category.', $attributeKey), $attributeKey, InvalidAttributeValueReason::NotInForce);
    }

    public function attributeKey(): string
    {
        return $this->attributeKey;
    }

    /** Null only for a "not in force" key that is not a well-formed id. */
    public function attributeId(): ?AttributeId
    {
        try {
            return AttributeId::fromString($this->attributeKey);
        } catch (InvalidAttributeId) {
            return null;
        }
    }

    public function reason(): InvalidAttributeValueReason
    {
        return $this->reason;
    }

    public function min(): ?int
    {
        return $this->min;
    }

    public function max(): ?int
    {
        return $this->max;
    }
}
