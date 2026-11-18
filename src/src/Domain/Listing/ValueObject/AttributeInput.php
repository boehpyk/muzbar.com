<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeId;
use App\Domain\Listing\Exception\InvalidAttributeValue;

/**
 * What the seller submitted per attribute, as text, before any rule has read it — the "Details"
 * half of a revise. Keys are attribute ids; a value becomes an `AttributeValue` only once
 * `AttributeSchema::read()` accepts it (L-9).
 *
 * Ids are checked for shape here, nothing more: whether the attribute is in force is a fact only
 * the schema knows.
 */
final readonly class AttributeInput
{
    /**
     * @param array<string, array{AttributeId, string}> $entries keyed by normalised attribute id
     */
    private function __construct(
        private array $entries,
    ) {
    }

    /**
     * Keys are `array-key`, not `string`: PHP turns a posted key like "123" into an int, and a
     * tampered key must be refused, not a `TypeError`.
     *
     * @param array<array-key, string> $raw attribute id → the seller's text
     *
     * @throws InvalidAttributeValue "not in force" for a key that is not an attribute id, or a
     *                               second key naming the same attribute (ids differing only in hex
     *                               case) — no form renders either, so both are tampering
     */
    public static function fromArray(array $raw): self
    {
        $entries = [];

        foreach ($raw as $key => $text) {
            $key = (string) $key;

            try {
                $attributeId = AttributeId::fromString($key);
            } catch (InvalidAttributeId) {
                throw InvalidAttributeValue::notInForce($key);
            }

            if (isset($entries[$attributeId->toString()])) {
                throw InvalidAttributeValue::notInForce($key);
            }

            $entries[$attributeId->toString()] = [$attributeId, $text];
        }

        return new self($entries);
    }

    /**
     * L-10's filter: only the inputs for attributes in force on `$schema`. Used by the aggregate on
     * a re-file, where the form was rendered for the old category; never otherwise, because with
     * the category unchanged an input that is not in force is refused, not dropped.
     */
    public function onlyFor(AttributeSchema $schema): self
    {
        return new self(array_filter(
            $this->entries,
            static fn (array $entry): bool => $schema->has($entry[0]),
        ));
    }

    /**
     * @return list<array{AttributeId, string}>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }
}
