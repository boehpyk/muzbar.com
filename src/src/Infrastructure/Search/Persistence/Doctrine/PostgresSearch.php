<?php

declare(strict_types=1);

namespace App\Infrastructure\Search\Persistence\Doctrine;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\Port\SearchPort;
use App\Domain\Search\ValueObject\AttributeId;
use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\FacetAttribute;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FacetKind;
use App\Domain\Search\ValueObject\FacetSchema;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\ListingHit;
use App\Domain\Search\ValueObject\SearchResult;
use App\Domain\Search\ValueObject\Selection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Faceted search over published listings, in PostgreSQL (ADR-0002) — Search's one adapter, and a
 * **read-only projection** over Catalog's and Listing's tables (ADR-0023). The fence:
 *
 * - **Read-only.** Nothing here writes; `SearchIsolationTest` scans this directory for write verbs.
 * - **One directory.** `Infrastructure/Search/` is the only place outside a table's own context that
 *   may name it (`ListingIsolationTest`). A column change in `listing_*`/`catalog_*`, or a new "not
 *   public" condition, must grep here and update {@see matching()} — S-1/S-2/S-6's one definition.
 * - **Never `seller_id`** (S-9): the hits name their columns; a column never read cannot leak.
 *
 * **No lock, no transaction** (plan D6). Each statement reads its own READ COMMITTED snapshot, so a
 * concurrent admin write can make one count stale for one page view. A public read queueing behind
 * `CategoryTreeLock::exclusively()` would cost more — do not "fix" this with `holdSteady()`.
 *
 * The statements (plan D5) — every value bound, every parameter name generated from an index:
 * 2. `facetSchemaFor()` — the lineage's active choice/boolean attributes and, of the requested
 *    option ids only, the active ones. None without a category.
 * 3. total, 4. hits — the matching fragment.
 * 5. counts for every unselected facet, and one count statement per selected facet with that
 *    facet's own selection left out (S-7, disjunctive). Labels come from the same join.
 */
final readonly class PostgresSearch implements SearchPort
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function facetSchemaFor(?CategoryId $category, FilterRequest $request): ?FacetSchema
    {
        if (null === $category) {
            return FacetSchema::none();
        }

        $requested = array_map(static fn ($id): string => $id->toString(), $request->requestedOptionIds());

        /** @var list<array{attribute_id: ?string, type: ?string, label: ?string, option_id: ?string, option_label: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "WITH RECURSIVE lineage (id, parent_id, depth_from_self) AS (
                SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, c.parent_id, lineage.depth_from_self + 1
                FROM catalog_category c JOIN lineage ON c.id = lineage.parent_id
                WHERE lineage.depth_from_self < :max_depth
            )
            SELECT a.id AS attribute_id, a.type, a.label, o.id AS option_id, o.label AS option_label
            FROM lineage
            LEFT JOIN catalog_attribute a ON a.category_id = lineage.id
                AND a.archived_at IS NULL AND a.type IN ('choice', 'boolean')
            LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id
                AND o.archived_at IS NULL AND ".([] === $requested ? 'false' : 'o.id IN (:requested)').'
            ORDER BY lineage.depth_from_self DESC, lower(a.label), a.id',
            ['category' => $category->toString(), 'max_depth' => Category::MAX_DEPTH] + ([] === $requested ? [] : ['requested' => $requested]),
            ['max_depth' => ParameterType::INTEGER, 'requested' => ArrayParameterType::STRING],
        );

        // The lineage always yields the category's own row, so no row at all means no category.
        if ([] === $rows) {
            return null;
        }

        $facets = [];
        $options = [];

        foreach ($rows as $row) {
            if (null === $row['attribute_id'] || null === $row['type'] || null === $row['label']) {
                continue;
            }

            $attributeId = AttributeId::fromString($row['attribute_id']);
            $facets[$row['attribute_id']] ??= new FacetAttribute($attributeId, FacetKind::from($row['type']), $row['label']);

            if (null !== $row['option_id'] && null !== $row['option_label']) {
                $options[$row['option_id']] = ['attribute' => $attributeId, 'label' => $row['option_label']];
            }
        }

        return FacetSchema::of(array_values($facets), $options);
    }

    public function search(FacetedQuery $query): SearchResult
    {
        [$with, $body, $params, $types] = $this->matching($query);

        /** @var int $total */
        $total = $this->connection->fetchOne($with.'SELECT count(*) '.$body, $params, $types);

        /** @var list<array{id: string, title: string, price_amount: int, price_currency: string, published_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $with.'SELECT l.id, l.title, l.price_amount, l.price_currency, l.published_at '.$body
            .' ORDER BY l.published_at DESC, l.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => FacetedQuery::PER_PAGE, 'offset' => $query->offset()],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $hits = array_map(static function (array $row): ListingHit {
            $currency = Currency::fromCode($row['price_currency']);

            return new ListingHit(
                $row['id'],
                $row['title'],
                Price::fromMinorUnits($row['price_amount'], $currency)->toDecimalString(),
                $currency->value,
                new \DateTimeImmutable($row['published_at'])->format(\DATE_ATOM),
            );
        }, $rows);

        return new SearchResult($hits, $total, $this->facets($query), $query);
    }

    /**
     * Every facet's buckets: one statement for all unselected facets, one per selected facet without
     * its own selection (S-7). None when there is no facet (no category).
     *
     * @return list<Facet>
     */
    private function facets(FacetedQuery $query): array
    {
        $schemaFacets = $query->facets()->facets();
        $unselected = [];
        $counts = [];
        $labels = [];

        foreach ($schemaFacets as $facet) {
            if (null === $query->selectionFor($facet->attributeId())) {
                $unselected[] = $facet->attributeId()->toString();
            }
        }

        if ([] !== $unselected) {
            $this->count($query, $unselected, null, $counts, $labels);
        }

        foreach ($query->selections() as $selection) {
            $this->count($query, [$selection->attributeId()->toString()], $selection, $counts, $labels);
        }

        $facets = [];

        foreach ($schemaFacets as $facet) {
            $id = $facet->attributeId()->toString();
            $built = Facet::of($facet, $counts[$id] ?? [], $labels[$id] ?? [], $query);

            if (null !== $built) {
                $facets[] = $built;
            }
        }

        return $facets;
    }

    /**
     * Counts the buckets of `$facetIds` over the listings matching every selection but `$omit`.
     * Filters on the raw `v.attribute_id` so the `(attribute_id, option_id)` index's `Index Cond`
     * applies (AC-18); an archived option joins to nothing and is dropped (S-4); booleans are counted
     * for `true` and `false` alike.
     *
     * @param non-empty-list<string>               $facetIds
     * @param array<string, array<string, int>>    $counts   attribute id => bucket value => count
     * @param array<string, array<string, string>> $labels   attribute id => option id => label
     */
    private function count(FacetedQuery $query, array $facetIds, ?Selection $omit, array &$counts, array &$labels): void
    {
        [$with, $body, $params, $types] = $this->matching($query, $omit);

        /** @var list<array{attribute_id: string, option_id: ?string, label: ?string, value_bool: ?bool, listings: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $with.'SELECT v.attribute_id, v.option_id, o.label, v.value_bool, count(*) AS listings
            FROM listing_attribute_value v
            JOIN (SELECT l.id '.$body.') m ON m.id = v.listing_id
            LEFT JOIN catalog_attribute_option o ON o.id = v.option_id AND o.archived_at IS NULL
            WHERE v.attribute_id IN (:facets) AND (o.id IS NOT NULL OR v.value_bool IS NOT NULL)
            GROUP BY v.attribute_id, v.option_id, o.label, v.value_bool',
            $params + ['facets' => $facetIds],
            $types + ['facets' => ArrayParameterType::STRING],
        );

        foreach ($rows as $row) {
            if (null !== $row['option_id']) {
                $counts[$row['attribute_id']][$row['option_id']] = $row['listings'];
                $labels[$row['attribute_id']][$row['option_id']] = (string) $row['label'];
            } elseif (null !== $row['value_bool']) {
                $counts[$row['attribute_id']][$row['value_bool'] ? 'yes' : 'no'] = $row['listings'];
            }
        }
    }

    /**
     * The matching fragment — S-1, S-2 and S-6 have exactly this one definition, used by every
     * statement. Returns the `WITH` prefix (the depth-bounded subtree, only when a category is
     * browsed), the `FROM … WHERE …` body, and its parameters. `$omit` leaves one selection out (S-7).
     *
     * @return array{string, string, array<string, mixed>, array<string, ArrayParameterType|ParameterType>}
     */
    private function matching(FacetedQuery $query, ?Selection $omit = null): array
    {
        $with = '';
        $join = '';
        $params = [];
        $types = [];

        if (null !== $query->category()) {
            $with = 'WITH RECURSIVE subtree (id, depth) AS (
                SELECT id, 1 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, subtree.depth + 1 FROM catalog_category c JOIN subtree ON c.parent_id = subtree.id
                WHERE subtree.depth < :max_depth
            ) ';
            $join = ' JOIN subtree s ON s.id = l.category_id';
            $params = ['category' => $query->category()->toString(), 'max_depth' => Category::MAX_DEPTH];
            $types = ['max_depth' => ParameterType::INTEGER];
        }

        $where = "l.status = 'published'";

        foreach ($query->selections() as $i => $selection) {
            if ($selection === $omit) {
                continue;
            }

            $column = FacetKind::Choice === $selection->kind() ? 'option_id' : 'value_bool';
            $where .= " AND EXISTS (SELECT 1 FROM listing_attribute_value f{$i}"
                ." WHERE f{$i}.listing_id = l.id AND f{$i}.attribute_id = :a{$i} AND f{$i}.{$column} IN (:v{$i}))";
            $params['a'.$i] = $selection->attributeId()->toString();
            $params['v'.$i] = FacetKind::Choice === $selection->kind()
                ? array_map(static fn ($id): string => $id->toString(), $selection->optionIds())
                : array_map(static fn (bool $b): string => $b ? 'true' : 'false', $selection->booleans());
            $types['v'.$i] = ArrayParameterType::STRING;
        }

        return [$with, 'FROM listing_listing l'.$join.' WHERE '.$where, $params, $types];
    }
}
