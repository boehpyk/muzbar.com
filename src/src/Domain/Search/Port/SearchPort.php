<?php

declare(strict_types=1);

namespace App\Domain\Search\Port;

use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FacetSchema;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\SearchResult;

/**
 * Faceted search over published listings (ADR-0002, ADR-0023). Two steps, so the second can accept
 * only a **resolved** query: read the facts a request is resolved against, then search.
 *
 * Reads only, takes no lock and opens no transaction (plan D6): each answer is its own snapshot, and
 * a concurrent admin write can at worst make one count stale for one page view.
 */
interface SearchPort
{
    /**
     * The facts `$request` is resolved against: `$category`'s facets (S-3) and, of the option ids
     * `$request` names, those that are active (S-4) — never a whole option list. `null` when
     * `$category` names no category; `FacetSchema::none()`, without a statement, when it is `null`.
     */
    public function facetSchemaFor(?CategoryId $category, FilterRequest $request): ?FacetSchema;

    /** One results page: hits (S-1, S-2, S-6, S-8), the total, and disjunctive facet counts (S-7). */
    public function search(FacetedQuery $query): SearchResult;
}
