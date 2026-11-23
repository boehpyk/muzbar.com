<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * A browse request **resolved** against the browsed category's facets — the only thing
 * `SearchPort::search()` accepts, so an unresolved filter reaching SQL is a type error (plan D4).
 *
 * `resolve()` is the only constructor and holds two policies that can be wrong (ADR-0012 d.2):
 *
 * - **S-5, second half:** a selection survives only for a facet in the schema, keeping only values
 *   that are buckets of **that** facet — an active option of that attribute for a choice, `yes`/`no`
 *   for a boolean. Values on a number facet are dropped. Everything else is dropped, silently; a
 *   selection left empty is dropped whole. With no category the schema is `none()`, so every
 *   selection is dropped.
 * - **S-10:** a range survives only on a **number** facet in the schema; on any other kind, or an
 *   attribute not in the schema, it is dropped. Nothing clamps it: the schema carries no `NumberRules`
 *   bounds, on purpose (S-12) — a stored value outside today's rules stays findable.
 * - **S-8:** the page is clamped to `1 … MAX_PAGE`; `PER_PAGE` hits per page.
 *
 * At most one selection per attribute, by construction: values survive only on choice/boolean facets,
 * ranges only on number facets, and `FilterRequest` keeps one range per attribute.
 *
 * The price filter (S-13) and the search terms (S-17) are carried through **independently of the
 * schema**: neither is a facet's selection, so both survive with no category at all.
 */
final readonly class FacetedQuery
{
    public const int PER_PAGE = 20;

    /** Bounds `OFFSET` (plan Risk 2); keyset paging is `search-benchmark`'s. */
    public const int MAX_PAGE = 50;

    /**
     * @param list<Selection> $selections
     */
    private function __construct(
        private ?CategoryId $category,
        private array $selections,
        private FacetSchema $facets,
        private ?PriceRange $price,
        private ?SearchTerms $text,
        private int $page,
    ) {
    }

    public static function resolve(?CategoryId $category, FilterRequest $request, FacetSchema $schema, int $page): self
    {
        $selections = [];

        foreach ($request->requested() as $entry) {
            $facet = $schema->facet($entry['attribute']);

            if (null === $facet) {
                continue;
            }

            $attribute = $facet->attributeId();
            $values = match ($facet->kind()) {
                FacetKind::Choice => array_values(array_filter(
                    $entry['values'],
                    static fn (OptionId|bool $v): bool => $v instanceof OptionId && $schema->optionBelongsTo($v, $attribute),
                )),
                FacetKind::Boolean => array_values(array_filter($entry['values'], \is_bool(...))),
                FacetKind::Number => [],
            };

            if ([] !== $values) {
                $selections[] = Selection::ofValues($attribute, $facet->kind(), $values);
            }
        }

        foreach ($request->requestedRanges() as $entry) {
            if (FacetKind::Number === $schema->facet($entry['attribute'])?->kind()) {
                $selections[] = Selection::ofRange($entry['attribute'], $entry['range']);
            }
        }

        return new self($category, $selections, $schema, $request->price(), $request->text(), max(1, min(self::MAX_PAGE, $page)));
    }

    public function category(): ?CategoryId
    {
        return $this->category;
    }

    /** @return list<Selection> value selections in request order, then range selections in request order */
    public function selections(): array
    {
        return $this->selections;
    }

    public function selectionFor(AttributeId $attributeId): ?Selection
    {
        foreach ($this->selections as $selection) {
            if ($selection->attributeId()->equals($attributeId)) {
                return $selection;
            }
        }

        return null;
    }

    public function facets(): FacetSchema
    {
        return $this->facets;
    }

    /** The price filter (S-13) — whatever the category, or none. */
    public function price(): ?PriceRange
    {
        return $this->price;
    }

    /** The search terms (S-17) — whatever the category, or none. */
    public function text(): ?SearchTerms
    {
        return $this->text;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }
}
