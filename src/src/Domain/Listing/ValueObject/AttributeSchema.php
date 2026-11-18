<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;

/**
 * Listing's view of a category's **effective schema** (ADR-0018 C-22): one `AttributeRule` per
 * attribute in force on the category — its own and every ancestor's. The `EffectiveSchema` ADR-0018
 * decision 8.4 deferred, in Listing's words (ADR-0020 decision 3).
 *
 * A **fact**, like `CategoryStatus`: **only true inside `Categories::holdSteady()`**. An admin may
 * delete an attribute, remove an option or move the category a moment after it is read; the shared
 * lock is what keeps the catalog still until the listing's values are saved, and C-25/C-26/C-27
 * are what stop Catalog from stranding them afterwards. Never stored on the Listing side.
 */
final readonly class AttributeSchema
{
    /**
     * @param array<string, AttributeRule> $rules keyed by attribute id
     */
    private function __construct(
        private array $rules,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws \InvalidArgumentException two rules for one attribute — the adapter's bug, not the
     *                                   seller's (even a legacy duplicate code is two attribute ids)
     */
    public static function of(AttributeRule ...$rules): self
    {
        $byId = [];

        foreach ($rules as $rule) {
            $key = $rule->attributeId()->toString();

            if (isset($byId[$key])) {
                throw new \InvalidArgumentException(\sprintf('Attribute "%s" appears twice in one schema.', $key));
            }

            $byId[$key] = $rule;
        }

        return new self($byId);
    }

    public function has(AttributeId $attributeId): bool
    {
        return isset($this->rules[$attributeId->toString()]);
    }

    /**
     * L-9 for every input: each is read against its attribute's rule; empty inputs are skipped (no
     * value — L-11). An input for an attribute not in this schema is **refused**, never dropped:
     * dropping is L-10's decision, taken by the aggregate on a re-file (`AttributeInput::onlyFor()`)
     * before it gets here.
     *
     * @throws InvalidAttributeValue
     */
    public function read(AttributeInput $input): AttributeValues
    {
        $values = [];

        foreach ($input->entries() as [$attributeId, $raw]) {
            $rule = $this->rules[$attributeId->toString()] ?? throw InvalidAttributeValue::notInForce($attributeId->toString());
            $value = $rule->read($raw);

            if (null !== $value) {
                $values[] = $value;
            }
        }

        return AttributeValues::of(...$values);
    }
}
