# Technical Plan: search-browse

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order. Written to the **recommendation** of every open question
> (feature-spec §Open questions).

## Decisions

### D1 — How `Search` reads Catalog's and Listing's tables → **ADR-0023** (new, `/adr`, task T0)

Today's facts: the gate query is **one statement** joining `catalog_category`, `catalog_attribute`,
`catalog_attribute_option`, `listing_listing` and `listing_attribute_value`. ADR-0019 decision 4 says
a table name appears only in its own context's Infrastructure, and two scans in
`ListingIsolationTest` enforce it **over all of `src/`** (`listing_attribute_value` only under
`Infrastructure/Listing/`, `catalog_attribute*` only under `Infrastructure/Catalog/`). So the gate
query cannot move into `src/` as written without a decision. Meanwhile five "UI crossings" already
read Catalog's read models from Listing controllers — the rule has been bent five times without being
restated.

| | **A. Search is a read-only projection context** *(recommended)* | **B. Composition through ports with id lists** |
|---|---|---|
| Shape | One adapter, `Infrastructure/Search/…/PostgresSearch`, reads both contexts' tables in single statements. ADR-0019 d.4 gains one named exception: *Search's Infrastructure may **read** any context's tables; it writes none.* | Search's Domain declares two ports in its own words (`CatalogForSearch`: subtree, facets, active option ids; `ListingsForSearch`: hits and counts given id lists). Adapters live with the data owners (`Infrastructure/Catalog/Integration/`, `Infrastructure/Listing/Integration/`), `PostgresSearch` composes them. |
| ADR-0019 d.4 | Amended (exception, fenced). | Kept literally. |
| Where Search's core SQL lives | `Infrastructure/Search/` — one place for slice 4 to tune, for slice 11 to add the expiry predicate, for slice 14 to reuse. | **In Listing's directory** — the facet/count SQL is Search's logic housed by Listing; Search's own Infrastructure is a facade. |
| The four "active" clauses | In SQL, each with its fixture row. | Split across two statements as id lists: `category_id = ANY(:subtree)`, `attribute_id = ANY(:facets)`, and for S-4 **every active option id of every facet** (a 5 000-entry Brand → 5 000 parameters per count), or the inverted *archived* ids (`NOT IN`, easy to get wrong). |
| Planner | Sees the whole query; one round trip per statement. | Two round trips; the second has huge literal arrays; selectivity estimates on parameter arrays are poor. |
| Coupling cost | A Catalog/Listing column change is a Search change. **Mitigated:** one directory, the lifted fixture fails loudly, a CLAUDE.md footgun, a read-only scan. | Same coupling, spread over two adapters plus two port contracts. |
| Classes | 1 adapter. | 2 ports + 2 adapters + 1 composer. |
| Meilisearch later (ADR-0002) | `SearchPort` swaps one binding; the projection is then an indexer's source. | Same. |

Also considered and **rejected for now** (recorded in the ADR):

- **C. A Search-owned denormalised table** (`search_listing`), maintained by event listeners. Listing
  emits only `ListingPublished` — no revise/withdraw/archive events — and this is exactly ADR-0002's
  rejected sync path ("write and read drift → silently empty"). Reconsider only if slice 4's benchmark
  fails *and* hot-column promotion (ADR-0004 §3) does not fix it.
- **D. Owner-published SQL views** (a Published Language at the database level). Attractive in DDD
  terms, but the subtree is a parameterised recursion a view cannot take, views are DDL Doctrine does
  not diff, and there is one reader. Reconsider if a second reader of the joined shape appears.

**Recommendation: A.** It is the honest DDD reading of the Constitution's own table (§4: Search owns
"the read model / faceted query port (no aggregate — a port)") and §4.5 ("the search index — today
Postgres itself — is derived"): Search is a **Conformist** downstream of two upstream schemas and a
**CQRS read side**. Aggregates are consistency boundaries for *writes*; a read model joining them is
not a violation, an unacknowledged one is. The fence is what makes it a rule rather than a bend:

1. **Read-only:** `SearchIsolationTest` fails on `INSERT|UPDATE|DELETE|TRUNCATE|ALTER|CREATE|DROP|MERGE`
   in the code of `Infrastructure/Search/` (comments stripped).
2. **One directory:** the two `ListingIsolationTest` table scans admit `Infrastructure/Search/` and
   nothing else.
3. **Privacy:** the same scan fails on `seller_id` anywhere in `Infrastructure/Search/`.
4. **Search's Domain and Application stay isolated** (no other context's classes) — local id copies
   (ADR-0019 d.2).
5. **No Search table, no Search migration** in this slice. A Search-owned table later (option C) is a
   new ADR.

ADR-0023 also states the **watch item**: every column rename or semantic change in `listing_listing`,
`listing_attribute_value`, `catalog_category`, `catalog_attribute`, `catalog_attribute_option` (and
every new "not public" condition, e.g. `listing-expiry`'s) must grep `Infrastructure/Search/`.

### D2 — No aggregate, no domain event, no repository

Search never writes, so it has no state to keep consistent and nothing to protect across concurrent
writes — an aggregate would be a class with no invariant. It emits no event (a read is not a fact the
business reacts to; analytics is Umami's). This matches the Constitution's context table. **The Domain
is not empty:** S-5 (which selections survive) and S-8's page rule are policies that can be wrong, so
they are pure functions in the Domain (ADR-0012 d.2), deciding on facts the port reads (ADR-0016's
shape, minus the lock — D6).

### D3 — `SearchPort`: two methods, the second accepts only a resolved query

```php
interface SearchPort
{
    /** The facts `$request` is resolved against; null when `$category` names no category. */
    public function facetSchemaFor(?CategoryId $category, FilterRequest $request): ?FacetSchema;

    public function search(FacetedQuery $query): SearchResult;
}
```

`facetSchemaFor()` reads the browsed category's facets **and only the requested option ids** — never a
whole option list (the `optionLabels()` lesson: a 5 000-entry Brand to check three ids). With no
category it returns `FacetSchema::none()` without a statement. Slice 14 (`search-alert-match`) will
add an id filter to `FacetedQuery`, not a second port.

### D4 — Two types: `FilterRequest` (asked) → `FacetedQuery` (runnable)

- `FilterRequest::parse(array<string, list<string>> $raw): self` — format only: UUID attribute keys;
  values that are a UUID (→ `OptionId`) or exactly `yes`/`no` (→ `bool`); dedupe; caps
  `MAX_ATTRIBUTES = 20`, `MAX_VALUES = 50` (first N in request order). Never throws. Exposes
  `requestedOptionIds(): list<OptionId>` for `facetSchemaFor()`.
- `FacetedQuery::resolve(?CategoryId, FilterRequest, FacetSchema, int $page): self` — the only
  constructor (private `__construct`). Keeps a selection only for a facet in the schema, keeps only
  values that are buckets of **that** facet (S-5), drops empties, clamps the page (S-8). Exposes
  `category()`, `selections(): list<Selection>`, `facets(): FacetSchema`, `page()`, `offset()`,
  `PER_PAGE = 20`, `MAX_PAGE = 50`.
- `Selection` — `AttributeId` + `FacetKind` + `list<OptionId>` (choice) or `list<bool>` (boolean);
  constructed only by `resolve()` (`@internal`, PHP has no package visibility).

"Parse, don't validate": an unresolved filter reaching SQL is a type error.

### D5 — The statements (all DBAL, all parameters bound, parameter **names** generated by index)

One private builder produces the **matching fragment**, used by every statement so S-1/S-2/S-6 have
exactly one definition (slice 11 adds its predicate here, once):

```sql
-- subtree CTE only when a category is browsed; bounded like DbalCategoryOutline (Category::MAX_DEPTH)
FROM listing_listing l
JOIN subtree s ON s.id = l.category_id                               -- S-2 (b)
WHERE l.status = 'published'                                          -- S-1 (a)
  AND EXISTS (SELECT 1 FROM listing_attribute_value f0                -- S-6: one EXISTS per selection,
              WHERE f0.listing_id = l.id AND f0.attribute_id = :a0    --      values OR'd inside it
                AND f0.option_id IN (:v0))                            --      (value_bool IN (:v1) for booleans)
```

| # | Statement | When |
|---|---|---|
| 1 | `DbalCategoryOutline::rows()` — breadcrumb, children, root list (reused, not Search's) | always |
| 2 | **facet schema**: `lineage` CTE `LEFT JOIN catalog_attribute a ON a.category_id = l.id AND a.archived_at IS NULL (c) AND a.type IN ('choice','boolean')`, `LEFT JOIN catalog_attribute_option o ON o.attribute_id = a.id AND o.archived_at IS NULL (g) AND o.id IN (:requested)`; ordered `depth_from_self DESC, lower(a.label), a.id` (the `effectiveFor()` order). Zero rows ⇔ unknown category; the lineage restriction is (e). | category given |
| 3 | **total**: `SELECT count(*)` + fragment | always |
| 4 | **hits**: `SELECT l.id, l.title, l.price_amount, l.price_currency, l.published_at` + fragment + `ORDER BY l.published_at DESC, l.id DESC LIMIT :limit OFFSET :offset` — **never `seller_id`, never `l.*`** | always |
| 5 | **counts, unselected facets**: `SELECT v.attribute_id, v.option_id, o.label, v.value_bool, count(*)` `FROM listing_attribute_value v JOIN (SELECT l.id` + fragment with **all** selections `) m ON m.id = v.listing_id LEFT JOIN catalog_attribute_option o ON o.id = v.option_id AND o.archived_at IS NULL (d)` `WHERE v.attribute_id IN (:facets) (f) AND (o.id IS NOT NULL OR v.value_bool IS NOT NULL)` `GROUP BY …` | ≥ 1 unselected facet |
| 6…5+k | **counts, one per selected facet X**: statement 5 with `:facets = [X]` and the fragment **without X's selection** (S-7, disjunctive) | per surviving selection |

Statement 5's `WHERE v.attribute_id IN (:facets)` is the generic index's way in (`Index Cond`
`attribute_id = ANY(…)`); (c) and (e) moved from the gate query's count into statement 2 — each keeps
its fixture row and its mutation. Bounds: ≤ 5 + k statements with a category, 3 without; k ≤ 20.

Rows are mapped into the result types by the adapter; `Facet::of()` (Domain, pure) assembles each
facet from its `FacetAttribute`, its count rows and its `Selection`.

### D6 — No lock, no transaction

Reads take **no** `CategoryTreeLock` (neither mode) and open no transaction: each statement reads its
own READ COMMITTED snapshot. The worst a concurrent admin write can do is make one count stale for one
page view; a public read queueing behind `exclusively()` would be worse. Written at the adapter's
class docblock so nobody "fixes" it by wrapping it in `holdSteady()`.

### D7 — Hits format the price with Listing's `Price`

`Price::fromMinorUnits($amount, Currency::fromCode($code))->toDecimalString()`, as `DbalSellerDrafts`
does — the Domain's one definition of how minor units read back. An Infrastructure → Listing-Domain
import (allowed by Deptrac; the context scans cover Domain/Application only). `publishedAt` is
`DATE_ATOM`. A second formatter in Search would be the drift.

### D8 — The controller normalises shapes; it never uses `InputBag`'s typed getters

`$request->query->all('f')` **throws `BadRequestException` (400)** when `f` is a string, and
`get('category')` / `getInt('page')` throw on arrays — each a non-200 for a hostile URL, contradicting
S-5. The controller reads `$request->query->all()` once and normalises:

- `category`: absent or `''` → null; a non-string → treated as malformed (the 404 path); a string →
  passed through (the handler's `CategoryId::fromString()` decides).
- `f`: not an array → `[]`; keep string keys whose value is an array; inside, keep string values
  only (scalars and nested arrays dropped) → `array<string, list<string>>`.
- `page`: `filter_var($raw, FILTER_VALIDATE_INT)` when a string, else 1; the Domain clamps.

Shapes only — every *meaning* (formats, caps, schema) is decided in the Domain.

### D9 — The EXPLAIN replays the adapter's own statements

`QueryRecordingStatement` gains a `bindValue()` override recording `[sql, params]` per statement
(DBAL 4's PDO driver binds through `PDOStatement::bindValue()`; verify first — Risk 4). The EXPLAIN
test drives the handler, picks the recorded count statement (statement 5) and the hits statement with
a selection, and re-runs `EXPLAIN (FORMAT JSON) <sql>` with the same values under the phase1-gate
`SET LOCAL` switches. The plan-walking helper (`planNodes()`) moves with it. No production code is
shaped for the test.

### D10 — Carry-over 1 is a **move**

`FACET_COUNT`, section B (`facetCount()`, `buildTheFacetFixture()` and its helpers, five tests) and
section C (the EXPLAIN test, `planNodes()`) leave `SchemaMutationGateTest`. The fixture becomes the
`BuildsTheFacetFixture` trait (`tests/Support/`), used by `BrowseListingsTest` and
`SearchIndexUsageTest`. Section A stays — it is the gate's end-to-end proof and touches no count.

### D11 — No migration, no index, no Doctrine mapping

The generic `(attribute_id, option_id)` index and the `listing_listing(category_id)` index are what
the statements need to be *usable* (AC-18). Sort support (`status, published_at`), partial indexes,
keyset paging and promotion are `search-benchmark`'s, on `ANALYZE`d data (roadmap carry-over 3).
Search has no entity, so no `doctrine.orm.mappings` block.

## Domain layer (pure PHP) — `src/Domain/Search/`

- **Aggregate / entity changes:** **none** (D2).
- **Value objects** (`ValueObject/`):
  - `CategoryId`, `AttributeId`, `OptionId` — local copies, `use UuidIdShape` with a `malformed()`
    hook (CLAUDE.md shape); exceptions `Exception/InvalidCategoryId`, `InvalidAttributeId`,
    `InvalidOptionId`. Added to `UuidIdShapeTest`'s ring.
  - `FacetKind` — enum `Choice = 'choice'`, `Boolean = 'boolean'` (Search's word for the two
    facetable attribute kinds; number arrives in slice 2).
  - `FilterRequest`, `FacetedQuery`, `Selection` (D4).
  - `FacetSchema` — `list<FacetAttribute>` in display order + `array<string optionId, {attribute:
    AttributeId, label: string}>` for the requested active options; `none()`; `facet(AttributeId):
    ?FacetAttribute`; `optionBelongsTo(OptionId, AttributeId): bool`.
  - `FacetAttribute` — `AttributeId`, `FacetKind`, `label`.
  - Results: `SearchResult` (`list<ListingHit> $hits`, `int $total`, `list<Facet> $facets`,
    `FacetedQuery $query`), `ListingHit` (`id`, `title`, `price`, `currency`, `publishedAt` — strings,
    no seller field exists to fill), `Facet` (`FacetAttribute`, `list<FacetBucket>`, `::of()`),
    `FacetBucket` (`value` = option id or `yes`/`no`, `label`, `count`, `selected`).
- **Domain events:** none (D2).
- **Ports:** `Port/SearchPort` (D3).
- **Exceptions:** `Exception/UnknownCategory` (the handler's 404 signal).

## Application layer — `src/Application/Search/`

- **Query:** `Query/BrowseListings(?string $category, array<string, list<string>> $filters, int $page)`
  — every field required (no defaults: the controller states each).
- **Handler:** `Handler/BrowseListingsHandler::__invoke(BrowseListings): SearchResult`:
  1. `$category = null === $q->category ? null : CategoryId::fromString(...)` — `InvalidCategoryId`
     → `UnknownCategory` (no statement issued: AC-21).
  2. `$request = FilterRequest::parse($q->filters)`.
  3. `$schema = $this->search->facetSchemaFor($category, $request) ?? throw UnknownCategory`.
  4. `return $this->search->search(FacetedQuery::resolve($category, $request, $schema, $q->page))`.
- **Transaction boundary:** none (D6). **Idempotency:** a pure read.

## Infrastructure layer — `src/Infrastructure/Search/`

- **Persistence:** `Persistence/Doctrine/PostgresSearch implements SearchPort` (D5–D7), DBAL
  `Connection` only; class docblock carries ADR-0023's fence, D6's "no lock" and the "never
  `seller_id`" rule. No mapping, no migration (D11).
- **HTTP / UI:** `Http/Controller/BrowseController` — `#[Route('/browse', name: 'app_browse',
  methods: ['GET'])]`, no route `requirements`, no `#[IsGranted]`, no `access_control` line (public).
  Normalises input (D8), calls the handler, catches `UnknownCategory` → `createNotFoundException()`,
  then reads `DbalCategoryOutline::rows()` for the breadcrumb (walk `parentId` up), the children
  (`parentId = current`), or the roots (no category). Lookup is tolerant: a category missing from the
  outline renders without a breadcrumb.
  `templates/search/browse.html.twig` — unstyled semantic markup, stable classes (`search-browse`,
  `search-breadcrumb`, `search-categories`, `search-facets`, `search-facet`, `search-results`,
  `search-hit`, `search-pager`), `<meta name="robots" content="noindex">` via a `head` block (Q13),
  no `<script>` of its own. Pager links built with `path('app_browse', {category, f, page})`.
  `templates/base.html.twig` — one `<a href="{{ path('app_browse') }}">Browse</a>` (Q10).
- **Async / schedule / external:** none.
- **DI wiring:** `config/services.yaml`: `App\Domain\Search\Port\SearchPort:
  '@App\Infrastructure\Search\Persistence\Doctrine\PostgresSearch'`. Deptrac: no change (the
  directory collectors already cover `src/*/Search/`).

## Interface boundary & input contract

- **HTTP:** `GET /browse?category=<uuid>&f[<attribute uuid>][]=<option uuid|yes|no>&page=<int>` —
  every parameter optional. Responses: **200** (always, for any `f`/`page`), **404** (category given
  and malformed, non-scalar, or unknown — one body). No other status by design.
- **Application:** `BrowseListings` as above; throws only `UnknownCategory`.
- **Domain:** `SearchPort` as in D3; `search()` accepts only a `FacetedQuery` built by `resolve()`.
- **Rejected input never reaches SQL:** malformed ids die in `FilterRequest::parse()` /
  `CategoryId::fromString()`; unknown ones in `resolve()`; SQL sees only bound parameters of resolved
  VOs, with parameter names generated from indexes — never from a request key.

## Data & migrations

- **None.** No table, column, index or mapping (D11). Reads: `catalog_category` (via `parent_id`,
  PK), `catalog_attribute` (by `category_id` — unindexed today, small table), `catalog_attribute_option`
  (PK), `listing_listing` (`idx_listing_listing_category`), `listing_attribute_value`
  (`idx_listing_attribute_value_option`, PK). Index needs beyond usability: slice 4.

## Test plan

- **Domain unit (no kernel)** — `tests/Unit/Domain/Search/`: `FilterRequestTest` (AC-1),
  `FacetedQueryTest` (AC-2, AC-3), `FacetTest` (AC-4), `SearchIsolationTest` (AC-5);
  `UuidIdShapeTest` ring (+3).
- **Integration (DAMA, through `BrowseListingsHandler`)** — `tests/Integration/Search/`:
  `BrowseListingsTest` (AC-6…AC-17; the lifted `BuildsTheFacetFixture` for AC-6…AC-14, own small
  fixtures for AC-15/AC-16), `SearchIndexUsageTest` (AC-18, D9).
- **Functional** — `tests/Functional/Search/BrowsePageTest` (AC-19…AC-24): anonymous client;
  `QueryRecordingStatement` for AC-20/AC-23; `ReadsListingPages::normalised()` for AC-21; the
  Brand-of-50 setup from `PublicListingTest` for AC-23.
- **Changed tests** — `SchemaMutationGateTest` (sections B/C removed, D10); `ListingIsolationTest`
  (two scans admit `Infrastructure/Search/`); `QueryRecordingStatement` (records bound values).
- **Performance** — usability only (AC-18). Latency: `search-benchmark`.
- **Mutation proof** — AC-25's seventeen, each run and reverted, catcher named in the commit message.

## ADRs

- **ADR-0023 (new, T0):** *Search is a read-only projection over Catalog's and Listing's tables* —
  D1 (A over B; C and D recorded), D2 (no aggregate), D6 (no lock), D3/D4 (port shape).
- **ADR-0019 amendment:** decision 4 gains Search's read-only exception; the "crossings" count stops —
  Search's reads are the rule, not a sixth crossing.
- **ADR-0002 amendment:** the port exists — two methods, resolved `FacetedQuery`, disjunctive counts;
  its last consequence ("design the port before the read model") discharged.
- **ADR-0004 amendment:** 2026-11-22 point 5's carry-overs discharged (fixture lifted, D1 decided);
  `is_facet` still deferred (every active choice/boolean attribute is a facet); still zero promoted
  columns.

## Risks / open questions

1. **Up to 25 statements per page** (20 selected facets). Disjunctive counts cost one statement per
   selected facet. Slice 4 measures; the upgrade path is one statement that counts, per listing, how
   many selections it misses and which (counts = misses 0, or misses 1 on the bucket's own facet).
   Not now: clever SQL with no measurement behind it.
2. **`OFFSET` paging** degrades with depth; `MAX_PAGE = 50` bounds it. Keyset paging is slice 4/7's.
3. **Long bucket lists:** a Brand facet in a broad category may render hundreds of checkboxes. Ugly,
   correct, unstyled; `search-ui` owns "show more".
4. **D9's assumption** that DBAL 4 binds via `PDOStatement::bindValue()`. Check before writing the
   test; if false, record via `execute()`'s argument or (last resort) build the SQL in a small
   `SearchStatements` class the test can call — say which in the commit.
5. **Coupling (ADR-0023's cost):** a rename in `listing_*`/`catalog_*` breaks Search at runtime, not
   at compile time. Caught by `BrowseListingsTest`; recorded as a CLAUDE.md footgun and ADR-0023's
   watch item. `listing-expiry` (11) must add its predicate to the matching fragment.
6. **The facet-schema statement reads `catalog_attribute` by `category_id`**, served by
   `uniq_catalog_attribute_category_code (category_id, code)`, whose leading column it is. Noted so
   slice 4 does not add a duplicate index.
7. **Fixture clock:** AC-15's same-second tie needs `published_at` set directly by DBAL after the
   factory publishes (the `Clock` is whole-second, and the factory's clock is real). Test-only write.
8. **`ListingFactory::published()` enforces L-13**: every fixture listing needs Condition (and Brand
   on Electric) — the lifted fixture already does.
