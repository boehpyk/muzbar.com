<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One facet of the browsed category (S-3): an **active** `choice`, `boolean` or `number` attribute in
 * the category's lineage, with the label a visitor sees and — for a number — its unit (`pcs`, `mm`),
 * `null` otherwise or when the admin gave none. A read-model fact, built by the adapter.
 *
 * The unit is the only part of `NumberRules` a read carries: the bounds are a write rule and play no
 * part in a search (S-12).
 */
final readonly class FacetAttribute
{
    public function __construct(
        private AttributeId $attributeId,
        private FacetKind $kind,
        private string $label,
        private ?string $unit = null,
    ) {
    }

    public function attributeId(): AttributeId
    {
        return $this->attributeId;
    }

    public function kind(): FacetKind
    {
        return $this->kind;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function unit(): ?string
    {
        return $this->unit;
    }
}
