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
use App\Domain\Search\ValueObject\RangeFacet;
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
 * The statements (search-browse plan D5, search-range-facets plan D7) — every value bound, every
 * parameter name generated from an index:
 * 2. `facetSchemaFor()` — the lineage's active choice/boolean/number attributes (a number's unit is
 *    the only `number_rules` field read) and, of the requested option ids only, the active ones.
 *    None without a category.
 * 3. total, 4. hits — the matching fragment.
 * 5. counts for every unselected facet, and one count statement per selected facet with that
 *    facet's own selection left out (S-7, S-11: disjunctive). Labels come from the same join; a
 *    number attribute's rows fold into **one** row through the same `GROUP BY` (`option_id`, `label`
 *    and `value_bool` are all NULL), carrying `min`/`max` of `value_int` — never group by
 *    `value_int`, or the count becomes per value. Number facets add no statement.
 *
 * **The one fragment** ({@see matching()}) holds every predicate — status, subtree, choice/boolean
 * selections, range selections and the price filter (S-1/S-2/S-6/S-11/S-13) — so total, hits and
 * every count apply them alike. The price filter is no facet's own selection: never omitted.
 *
 * **S-12: no `NumberRules` clause, on purpose.** A range is matched against the stored value only;
 * bounds narrowed after a value was stored neither clamp nor hide it (never retroactive).
 *
 * **No cast on a bound.** Range and price bounds are bound as `ParameterType::INTEGER`; pdo_pgsql
 * sends them untyped and PostgreSQL types them from the `bigint` column, so int64 round-trips
 * (AC-18). An `::int` cast would overflow above 2^31.
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

        /** @var list<array{attribute_id: ?string, type: ?string, label: ?string, unit: ?string, option_id: ?string, option_label: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "WITH RECURSIVE lineage (id, parent_id, depth_from_self) AS (
                SELECT id, parent_id, 0 FROM catalog_category WHERE id = :category
                UNION ALL
                SELECT c.id, c.parent_id, lineage.depth_from_self + 1
                FROM catalog_category c JOIN lineage ON c.id = lineage.parent_id
                WHERE lineage.depth_from_self < :max_depth
            )
            SELECT a.id AS attribute_id, a.type, a.label, a.number_rules->>'unit' AS unit, o.id AS option_id, o.label AS option_label
            FROM lineage
            LEFT JOIN catalog_attribute a ON a.category_id = lineage.id
                AND a.archived_at IS NULL AND a.type IN ('choice', 'boolean', 'number')
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
            $facets[$row['attribute_id']] ??= new FacetAttribute($attributeId, FacetKind::from($row['type']), $row['label'], $row['unit']);

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
     * Every facet's buckets or stats: one statement for all unselected facets, one per selected facet
     * without its own selection (S-7, S-11). None when there is no facet (no category). Schema order.
     *
     * @return list<Facet|RangeFacet>
     */
    private function facets(FacetedQuery $query): array
    {
        $schemaFacets = $query->facets()->facets();
        $unselected = [];
        $counts = [];
        $labels = [];
        $stats = [];

        foreach ($schemaFacets as $facet) {
            if (null === $query->selectionFor($facet->attributeId())) {
                $unselected[] = $facet->attributeId()->toString();
            }
        }

        if ([] !== $unselected) {
            $this->count($query, $unselected, null, $counts, $labels, $stats);
        }

        foreach ($query->selections() as $selection) {
            $this->count($query, [$selection->attributeId()->toString()], $selection, $counts, $labels, $stats);
        }

        $facets = [];

        foreach ($schemaFacets as $facet) {
            $id = $facet->attributeId()->toString();
            $built = FacetKind::Number === $facet->kind()
                ? RangeFacet::of($facet, $stats[$id]['count'] ?? 0, $stats[$id]['lo'] ?? null, $stats[$id]['hi'] ?? null, $query)
                : Facet::of($facet, $counts[$id] ?? [], $labels[$id] ?? [], $query);

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
     * for `true` and `false` alike. A number attribute folds into one row — `count(*)` is the
     * listings holding a value (the PK is `(listing_id, attribute_id)`), `lo`/`hi` the observed range.
     *
     * @param non-empty-list<string>                               $facetIds
     * @param array<string, array<string, int>>                    $counts   attribute id => bucket value => count
     * @param array<string, array<string, string>>                 $labels   attribute id => option id => label
     * @param array<string, array{count: int, lo: ?int, hi: ?int}> $stats    number attribute id => count, observed range
     */
    private function count(FacetedQuery $query, array $facetIds, ?Selection $omit, array &$counts, array &$labels, array &$stats): void
    {
        [$with, $body, $params, $types] = $this->matching($query, $omit);

        /** @var list<array{attribute_id: string, option_id: ?string, label: ?string, value_bool: ?bool, listings: int, lo: ?int, hi: ?int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            $with.'SELECT v.attribute_id, v.option_id, o.label, v.value_bool, count(*) AS listings,
                min(v.value_int) AS lo, max(v.value_int) AS hi
            FROM listing_attribute_value v
            JOIN (SELECT l.id '.$body.') m ON m.id = v.listing_id
            LEFT JOIN catalog_attribute_option o ON o.id = v.option_id AND o.archived_at IS NULL
            WHERE v.attribute_id IN (:facets)
                AND (o.id IS NOT NULL OR v.value_bool IS NOT NULL OR v.value_int IS NOT NULL)
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
            } else {
                $stats[$row['attribute_id']] = ['count' => $row['listings'], 'lo' => $row['lo'], 'hi' => $row['hi']];
            }
        }
    }

    /**
     * The matching fragment — S-1, S-2, S-6, S-11 and S-13 have exactly this one definition, used by
     * every statement. Returns the `WITH` prefix (the depth-bounded subtree, only when a category is
     * browsed), the `FROM … WHERE …` body, and its parameters. `$omit` leaves one selection out (S-7,
     * S-11); the price filter is never omitted. Bounds are bound as integers, never cast (AC-18), and
     * never compared with `NumberRules` (S-12).
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

            $range = $selection->range();

            if (null !== $range) {
                $where .= " AND EXISTS (SELECT 1 FROM listing_attribute_value f{$i}"
                    ." WHERE f{$i}.listing_id = l.id AND f{$i}.attribute_id = :a{$i}"
                    .(null === $range->min() ? '' : " AND f{$i}.value_int >= :lo{$i}")
                    .(null === $range->max() ? '' : " AND f{$i}.value_int <= :hi{$i}").')';
                $params['a'.$i] = $selection->attributeId()->toString();

                if (null !== $range->min()) {
                    $params['lo'.$i] = $range->min();
                    $types['lo'.$i] = ParameterType::INTEGER;
                }

                if (null !== $range->max()) {
                    $params['hi'.$i] = $range->max();
                    $types['hi'.$i] = ParameterType::INTEGER;
                }

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

        $price = $query->price();

        if (null !== $price) {
            $where .= ' AND l.price_currency = :pc';
            $params['pc'] = $price->currency()->value;

            if (null !== $price->range()?->min()) {
                $where .= ' AND l.price_amount >= :plo';
                $params['plo'] = $price->range()->min();
                $types['plo'] = ParameterType::INTEGER;
            }

            if (null !== $price->range()?->max()) {
                $where .= ' AND l.price_amount <= :phi';
                $params['phi'] = $price->range()->max();
                $types['phi'] = ParameterType::INTEGER;
            }
        }

        return [$with, 'FROM listing_listing l'.$join.' WHERE '.$where, $params, $types];
    }
}
