<?php

declare(strict_types=1);

namespace App\Application\Search\Handler;

use App\Application\Search\Query\BrowseListings;
use App\Domain\Search\Exception\InvalidCategoryId;
use App\Domain\Search\Exception\UnknownCategory;
use App\Domain\Search\Port\SearchPort;
use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\SearchResult;

/**
 * Parse, read the facts, resolve, search. A pure read: no transaction, no lock (plan D6), no event.
 * A malformed category id is an unknown category, and is refused before any statement (AC-21).
 */
final readonly class BrowseListingsHandler
{
    public function __construct(
        private SearchPort $search,
    ) {
    }

    /**
     * @throws UnknownCategory the category is malformed or names no category (one 404)
     */
    public function __invoke(BrowseListings $query): SearchResult
    {
        $category = null === $query->category ? null : self::categoryId($query->category);
        $request = FilterRequest::parse($query->filters);
        $schema = $this->search->facetSchemaFor($category, $request)
            ?? throw UnknownCategory::withId($query->category ?? '');

        return $this->search->search(FacetedQuery::resolve($category, $request, $schema, $query->page));
    }

    private static function categoryId(string $value): CategoryId
    {
        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw UnknownCategory::withId($value, $e);
        }
    }
}
