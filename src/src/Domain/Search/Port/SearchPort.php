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
 *
 * **S-12 — write rules are not read rules.** An attribute's `NumberRules` bounds constrain what a
 * seller may store; they play no part here. A stored value outside today's bounds is matched,
 * counted and shown like any other (ADR-0021: never retroactive), and a typed bound outside them is
 * searched as typed. The unit is the only part of the rules a read carries.
 */
interface SearchPort
{
    /**
     * The facts `$request` is resolved against: `$category`'s facets (S-3 — active `choice`,
     * `boolean` and `number` attributes of its lineage, a number one with its unit) and, of the
     * option ids `$request` names, those that are active (S-4) — never a whole option list. `null`
     * when `$category` names no category; `FacetSchema::none()`, without a statement, when it is
     * `null`.
     */
    public function facetSchemaFor(?CategoryId $category, FilterRequest $request): ?FacetSchema;

    /**
     * One results page: hits (S-1, S-2, S-6, S-8), the total, and disjunctive facet counts (S-7).
     *
     * - A **range selection** matches a listing iff it holds a value for that attribute with
     *   `min ≤ value ≤ max`, each end only when given; a listing with no value never matches (S-11).
     * - A **number facet** is a `RangeFacet`: the count of matching listings holding a value and
     *   their observed min/max — disjunctive like a count, ignoring that facet's own range (S-11).
     * - The **price filter** keeps only listings priced in its currency and, when bounds are given,
     *   within them in minor units (S-13, ADR-0024). It is no facet's own selection, so it applies to
     *   the total, the hits **and every facet's count**, with or without a category.
     */
    public function search(FacetedQuery $query): SearchResult;
}
