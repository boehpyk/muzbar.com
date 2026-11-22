<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One facet of the browsed category (S-3): an **active** `choice` or `boolean` attribute in the
 * category's lineage, with the label a visitor sees. A read-model fact, built by the adapter.
 */
final readonly class FacetAttribute
{
    public function __construct(
        private AttributeId $attributeId,
        private FacetKind $kind,
        private string $label,
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
}
