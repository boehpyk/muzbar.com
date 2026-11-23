<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One results page: the hits (S-8 order), the total matching **every** selection and the price
 * filter, the facets to show in the schema's display order — a choice or boolean one assembled by
 * `Facet::of()`, a number one by `RangeFacet::of()` — and the resolved query, so a page shows only
 * the selections that survived S-5, S-10 and S-13 — and, when the query has search terms, which
 * match answered it (S-18).
 */
final readonly class SearchResult
{
    /**
     * @param list<ListingHit>       $hits
     * @param list<Facet|RangeFacet> $facets
     */
    public function __construct(
        private array $hits,
        private int $total,
        private array $facets,
        private FacetedQuery $query,
        private ?TextMatch $textMatch,
    ) {
    }

    /** @return list<ListingHit> */
    public function hits(): array
    {
        return $this->hits;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return list<Facet|RangeFacet> */
    public function facets(): array
    {
        return $this->facets;
    }

    public function query(): FacetedQuery
    {
        return $this->query;
    }

    /** Which match answered the request (S-18); `null` when it had no search terms. */
    public function textMatch(): ?TextMatch
    {
        return $this->textMatch;
    }
}
