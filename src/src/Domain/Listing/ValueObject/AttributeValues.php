<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * Everything a listing says about its category's attributes — its "Details". One immutable value
 * the aggregate holds whole (ADR-0020 decision 2): the Domain may not hold a Doctrine collection,
 * so the repository writes this set to `listing_attribute_value` and a `postLoad` listener reads it
 * back. Replaced as a whole on every revise, never edited in place.
 *
 * L-8: at most one value per attribute. Keyed and kept sorted by attribute id, so `equals()` is
 * order-independent and `toList()` (the repository's row order) is deterministic.
 */
final readonly class AttributeValues
{
    /**
     * @param array<string, AttributeValue> $byAttributeId sorted by key
     */
    private function __construct(
        private array $byAttributeId,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws \InvalidArgumentException two values for one attribute (L-8) — a programming error:
     *                                   `AttributeSchema::read()` and the loader produce one per key
     */
    public static function of(AttributeValue ...$values): self
    {
        $byAttributeId = [];

        foreach ($values as $value) {
            $key = $value->attributeId()->toString();

            if (isset($byAttributeId[$key])) {
                throw new \InvalidArgumentException(\sprintf('Attribute "%s" has more than one value (L-8).', $key));
            }

            $byAttributeId[$key] = $value;
        }

        ksort($byAttributeId, \SORT_STRING);

        return new self($byAttributeId);
    }

    /** The values whose attribute is in force on `$schema` — the ones every rule reads (L-17). */
    public function onlyFor(AttributeSchema $schema): self
    {
        return new self(array_filter(
            $this->byAttributeId,
            static fn (AttributeValue $value): bool => $schema->has($value->attributeId()),
        ));
    }

    /**
     * The values whose attribute is **not** in force on `$schema` — the dormant ones (L-17). Dormant
     * is a fact about the pair (value, today's schema), not a state of the value: nothing is stored.
     */
    public function notIn(AttributeSchema $schema): self
    {
        return new self(array_filter(
            $this->byAttributeId,
            static fn (AttributeValue $value): bool => !$schema->has($value->attributeId()),
        ));
    }

    public function for(AttributeId $attributeId): ?AttributeValue
    {
        return $this->byAttributeId[$attributeId->toString()] ?? null;
    }

    /**
     * @return list<AttributeValue> sorted by attribute id
     */
    public function toList(): array
    {
        return array_values($this->byAttributeId);
    }

    public function isEmpty(): bool
    {
        return [] === $this->byAttributeId;
    }

    public function equals(self $other): bool
    {
        if (array_keys($this->byAttributeId) !== array_keys($other->byAttributeId)) {
            return false;
        }

        foreach ($this->byAttributeId as $key => $value) {
            if (!$value->equals($other->byAttributeId[$key])) {
                return false;
            }
        }

        return true;
    }
}
