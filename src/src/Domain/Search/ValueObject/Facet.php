<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One facet as shown beside the results: its attribute and its buckets, assembled by `of()` (AC-4).
 */
final readonly class Facet
{
    /**
     * @param non-empty-list<FacetBucket> $buckets
     */
    private function __construct(
        private FacetAttribute $attribute,
        private array $buckets,
    ) {
    }

    /**
     * Builds `$attribute`'s facet from its count rows, or `null` when it has no bucket to show.
     *
     * The count input is the adapter's rows for **this** facet, flattened:
     *
     * - `$counts` — bucket value => count: an option id (`OptionId::toString()`) for a choice,
     *   `yes`/`no` for a boolean. Already disjunctive (S-7) and already free of archived options
     *   (S-4); a value absent from it counts 0.
     * - `$labels` — option id => label, for every option id in `$counts`. Ignored for a boolean.
     *
     * The rules:
     *
     * - an unselected bucket with count 0 is omitted;
     * - a **selected** bucket is kept even at 0 — so it can be unchecked — labelled from the query's
     *   `FacetSchema` (it is a requested active option, so the schema has it);
     * - choice buckets are ordered by label case-insensitively (`MB_CASE_LOWER_SIMPLE`), then by id;
     *   boolean buckets are *Yes* then *No*;
     * - a facet with no bucket is omitted (`null`). One with a selection always has one, so "unless
     *   it has a selection" needs no branch.
     *
     * @param array<string, int>    $counts
     * @param array<string, string> $labels
     */
    public static function of(FacetAttribute $attribute, array $counts, array $labels, FacetedQuery $query): ?self
    {
        $selection = $query->selectionFor($attribute->attributeId());

        $buckets = match ($attribute->kind()) {
            FacetKind::Choice => self::choiceBuckets($counts, $labels, $selection, $query->facets()),
            FacetKind::Boolean => self::booleanBuckets($counts, $selection),
            FacetKind::Number => throw new \LogicException(\sprintf('Attribute "%s" is a number facet: build it with RangeFacet::of().', $attribute->attributeId()->toString())),
        };

        return [] === $buckets ? null : new self($attribute, $buckets);
    }

    public function attribute(): FacetAttribute
    {
        return $this->attribute;
    }

    /** @return non-empty-list<FacetBucket> */
    public function buckets(): array
    {
        return $this->buckets;
    }

    /**
     * @param array<string, int>    $counts
     * @param array<string, string> $labels
     *
     * @return list<FacetBucket>
     */
    private static function choiceBuckets(array $counts, array $labels, ?Selection $selection, FacetSchema $schema): array
    {
        $buckets = [];

        foreach ($counts as $value => $count) {
            $value = (string) $value;
            $selected = null !== $selection && $selection->selects($value);

            if ($count > 0 || $selected) {
                $buckets[$value] = new FacetBucket($value, $labels[$value] ?? $value, $count, $selected);
            }
        }

        foreach ($selection?->optionIds() ?? [] as $optionId) {
            $value = $optionId->toString();
            $buckets[$value] ??= new FacetBucket($value, $schema->optionLabel($optionId) ?? $value, 0, true);
        }

        usort($buckets, static fn (FacetBucket $a, FacetBucket $b): int => [self::sortKey($a->label()), $a->value()] <=> [self::sortKey($b->label()), $b->value()]);

        return $buckets;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<FacetBucket>
     */
    private static function booleanBuckets(array $counts, ?Selection $selection): array
    {
        $buckets = [];

        foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $label) {
            $count = $counts[$value] ?? 0;
            $selected = null !== $selection && $selection->selects($value);

            if ($count > 0 || $selected) {
                $buckets[] = new FacetBucket($value, $label, $count, $selected);
            }
        }

        return $buckets;
    }

    private static function sortKey(string $label): string
    {
        return mb_convert_case($label, \MB_CASE_LOWER_SIMPLE);
    }
}
