<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One **number** facet as shown beside the results — a *from / to* pair, not a list of buckets
 * (S-3 widened): its attribute (label, unit), how many matching listings hold a value for it, the
 * **observed range** of those values, and the range the visitor selected, if any.
 *
 * Count and observed range are **disjunctive** (S-11): they ignore this facet's own range and apply
 * every other selection and the price filter. The observed range is a display hint, never a limit on
 * what may be typed — and nothing here knows the attribute's `NumberRules` bounds (S-12).
 */
final readonly class RangeFacet
{
    private function __construct(
        private FacetAttribute $attribute,
        private int $count,
        private ?NumberRange $observed,
        private ?NumberRange $selected,
    ) {
    }

    /**
     * Builds `$attribute`'s facet from its count row, or `null` when there is nothing to show:
     *
     * - count 0 and no selection → `null` (omitted);
     * - count 0 **with** a selection → kept, so the range can still be cleared; observed range `null`;
     * - otherwise the count, the observed `$min … $max` and the selected range.
     *
     * @throws \LogicException `$attribute` is not a number facet — the adapter's bug
     */
    public static function of(FacetAttribute $attribute, int $count, ?int $min, ?int $max, FacetedQuery $query): ?self
    {
        if (FacetKind::Number !== $attribute->kind()) {
            throw new \LogicException(\sprintf('Attribute "%s" is not a number facet: build it with Facet::of().', $attribute->attributeId()->toString()));
        }

        $selected = $query->selectionFor($attribute->attributeId())?->range();

        if (0 === $count && null === $selected) {
            return null;
        }

        return new self($attribute, $count, 0 === $count ? null : NumberRange::of($min, $max), $selected);
    }

    public function attribute(): FacetAttribute
    {
        return $this->attribute;
    }

    /** Matching listings that hold a value for this attribute, ignoring this facet's own range. */
    public function count(): int
    {
        return $this->count;
    }

    /** The smallest and largest value among those listings; `null` when there are none. */
    public function observed(): ?NumberRange
    {
        return $this->observed;
    }

    /** The visitor's range on this facet, as it survived S-10; `null` when none. */
    public function selected(): ?NumberRange
    {
        return $this->selected;
    }
}
