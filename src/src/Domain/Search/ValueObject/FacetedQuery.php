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
 *   for a boolean. Everything else is dropped, silently; a selection left empty is dropped whole.
 *   With no category the schema is `none()`, so every selection is dropped.
 * - **S-8:** the page is clamped to `1 … MAX_PAGE`; `PER_PAGE` hits per page.
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
            };

            if ([] !== $values) {
                $selections[] = new Selection($attribute, $facet->kind(), $values);
            }
        }

        return new self($category, $selections, $schema, max(1, min(self::MAX_PAGE, $page)));
    }

    public function category(): ?CategoryId
    {
        return $this->category;
    }

    /** @return list<Selection> in request order */
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

    public function page(): int
    {
        return $this->page;
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }
}
