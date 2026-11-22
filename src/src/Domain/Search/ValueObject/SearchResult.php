<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One results page: the hits (S-8 order), the total matching **every** selection, the facets to
 * show (each assembled by `Facet::of()`, in the schema's display order), and the resolved query —
 * so a page shows only the selections that survived S-5.
 */
final readonly class SearchResult
{
    /**
     * @param list<ListingHit> $hits
     * @param list<Facet>      $facets
     */
    public function __construct(
        private array $hits,
        private int $total,
        private array $facets,
        private FacetedQuery $query,
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

    /** @return list<Facet> */
    public function facets(): array
    {
        return $this->facets;
    }

    public function query(): FacetedQuery
    {
        return $this->query;
    }
}
