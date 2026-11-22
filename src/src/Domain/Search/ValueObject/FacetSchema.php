<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * The facts a `FilterRequest` is resolved against (S-3, S-5), as `SearchPort::facetSchemaFor()`
 * read them: the browsed category's facets in display order, and — of the option ids the visitor
 * **requested**, never a whole option list — those that are active, with their attribute and label.
 *
 * A requested option that is unknown or archived is simply absent, so `resolve()` drops it without
 * knowing why (S-4, S-5). No category → `none()`: no facets, so every selection is dropped.
 *
 * A fact read without a lock (plan D6): a concurrent admin write can make it stale for one page view.
 */
final readonly class FacetSchema
{
    /**
     * @param array<string, FacetAttribute>                               $facets  keyed by attribute id, in display order
     * @param array<string, array{attribute: AttributeId, label: string}> $options keyed by option id
     */
    private function __construct(
        private array $facets,
        private array $options,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param list<FacetAttribute>                                        $facets  in display order
     * @param array<string, array{attribute: AttributeId, label: string}> $options the requested
     *                                                                             options that are active, keyed by option id (`OptionId::toString()`)
     *
     * @throws \InvalidArgumentException one attribute twice — the adapter's bug, not the visitor's
     */
    public static function of(array $facets, array $options): self
    {
        $byId = [];

        foreach ($facets as $facet) {
            $key = $facet->attributeId()->toString();

            if (isset($byId[$key])) {
                throw new \InvalidArgumentException(\sprintf('Attribute "%s" appears twice in one facet schema.', $key));
            }

            $byId[$key] = $facet;
        }

        return new self($byId, $options);
    }

    /** @return list<FacetAttribute> in display order */
    public function facets(): array
    {
        return array_values($this->facets);
    }

    public function facet(AttributeId $attributeId): ?FacetAttribute
    {
        return $this->facets[$attributeId->toString()] ?? null;
    }

    /** S-5: an option id is a bucket of **that** facet only — another facet's option is dropped. */
    public function optionBelongsTo(OptionId $optionId, AttributeId $attributeId): bool
    {
        $option = $this->options[$optionId->toString()] ?? null;

        return null !== $option && $option['attribute']->equals($attributeId);
    }

    /** The label of a requested active option — how a selected bucket at count 0 is still named (AC-4). */
    public function optionLabel(OptionId $optionId): ?string
    {
        return $this->options[$optionId->toString()]['label'] ?? null;
    }
}
