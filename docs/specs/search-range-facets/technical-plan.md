# Technical Plan: search-range-facets

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order. **Owner answers 2026-11-23:** Q1 = A; Q1b and Q2–Q9 as recommended;
> Q10 (new) — `UAH` leaves the allow-list, sell-form default `EUR` (D12, task T1).

## Decisions

### D1 — Price across currencies → **ADR-0024** (new, `/adr`, task T0) — **ANSWERED: A** (owner, 2026-11-23)

Facts: `Currency` is a closed enum, **three** codes after D12 (`USD`, `EUR`, `SEK`; `UAH` was the
fourth until 2026-11-23), all with 2 minor digits. `listing_listing` stores `price_amount bigint` (minor units) + `price_currency varchar(3)`.
`Price` defines `equals()` across both parts and **no ordering**; ADR-0019's Consequences say
"Price facets and sorting across currencies must be decided by Phase 2 before `price_amount` is
indexed … Until then, nothing sorts or filters on price."

| | **A. One chosen currency** *(chosen, owner 2026-11-23)* | B. Static rate table | C. Live daily rates | D. Normalised column at write |
|---|---|---|---|---|
| Moving parts | none | config + owner discipline | job (ADR-0012 d.5 re-opened), a rates provider, Search table (ADR-0023 fence 5 → new ADR), failure mode for missing rates | Listing migration + backfill; write-path change for a read need |
| Correctness | exact within a currency | silently wrong by the drift | right to the day; wrong when a fetch fails | wrong from the next day |
| Index later (slice 4) | `(price_currency, price_amount)` serves it directly | expression over a join — no plain index | same as B | plain index |
| Honest to the model | yes — `Price` has no cross-currency order | invents an order the business does not own | same, with fresher numbers | freezes a guess into Listing |

**Chosen: A** (owner, 2026-11-23), with **Q1b = drop bounds without a currency**. The SQL is one predicate in the
matching fragment:

```sql
AND l.price_currency = :pc [AND l.price_amount >= :plo] [AND l.price_amount <= :phi]
```

ADR-0024 records: A over B/C/D; the **future-sort rule** (a price sort is offered only with a
currency chosen, ordering `price_amount` within it); the **revisit trigger** (≥ 2 currencies each
≥ 20 % of one top-level category's published listings, or a cross-currency sort request → option C
as its own ADR — noting that if `UAH` has returned by then, the ECB's reference rates are believed not to
cover it, so a second source such as the National Bank of Ukraine; verify then); the mirror (D4);
and the **three-code list** `USD`, `EUR`, `SEK` (D12).

### D2 — Still no aggregate, no event, no new port method

Search writes nothing (ADR-0023 d.3). The new policies — which ranges survive (S-10), swap, whole
units → minor units, currency allow-list (S-13) — are pure Domain functions. `SearchPort`'s two
signatures are **unchanged**: `facetSchemaFor()` now also returns number facets; `search()` still
takes only a resolved `FacetedQuery`, which now may carry range selections and a price filter.

### D3 — `NumberRange`: non-empty and never inverted, by construction

```php
final readonly class NumberRange {
    public static function of(?int $min, ?int $max): ?self; // null when both null; swaps when min > max
    public function min(): ?int; public function max(): ?int;
}
```

Whole numbers only (`NumberRules` is whole numbers; `value_int` is `bigint`; PHP `int` is int64).
Used for attribute ranges and, in minor units, price ranges. No `contains()` — SQL is the only
evaluator (YAGNI; `search-alert-match` reuses `SearchPort`, not PHP).

### D4 — Search's own `Currency` enum (a mirror, like the local ids)

Search's Domain may not import Listing (`SearchIsolationTest`, ADR-0023 fence point 4), yet the
allow-list and the exponent are needed *in the Domain* to resolve and convert a bound. So
`Domain/Search/ValueObject/Currency` copies Listing's cases by **value** (the `FacetKind` ↔
`AttributeType` precedent) and its `minorUnits()`. It is built **after** D12 (T1 before T3), so it is
born with three codes and never held `UAH`. Drift is caught by `CurrencyMirrorTest` (S-14,
AC-5), a unit test that reads both enums (tests are not scanned). Drift direction is safe either way
except the exponent, which is exactly what the test pins. The adapter keeps formatting hits with
Listing's `Price` (search-browse D7) — unchanged.

Rejected: parsing price bounds in the controller with `Price::fromDecimal()` (Infrastructure may
import Listing) — that decides *meaning* outside the Domain, against search-browse D8.

### D5 — `FilterRequest::parse()` gains ranges and price (format only, never throws)

```php
public static function parse(array $values, array $ranges, array $price): self
//  $values  array<array-key, list<string>>                       f[<id>][]=…  (unchanged)
//  $ranges  array<array-key, array{min: ?string, max: ?string}>  r[<id>][min|max]
//  $price   array{currency: ?string, min: ?string, max: ?string} price[…]
```

- **Range bound:** `/^-?[0-9]{1,18}$/D` (no `u`, so ASCII digits only; 18 digits stay inside int64)
  → `(int)`. `''` (blank field) is absent. Anything else is dropped.
- **Price:** currency via `Currency::tryFrom()` (exact, upper-case); bound `/^[0-9]{1,7}$/D` →
  `(int) $b * 10 ** $currency->minorUnits()`; no currency → whole price filter dropped; currency
  with no bounds survives → `PriceRange($currency, null)`.
- **Cap:** `MAX_ATTRIBUTES = 20` distinct attribute ids across `$values` then `$ranges`, request
  order. `MAX_VALUES` unchanged.
- Exposes `requestedRanges(): list<array{attribute: AttributeId, range: NumberRange}>` and
  `price(): ?PriceRange`. `requestedOptionIds()` unchanged.

All 18 existing `parse()` call sites (tests) gain `[], [...]` — mechanical, one commit (T4).

### D6 — `Selection`, `FacetedQuery::resolve()`

- `Selection` gains kind `Number` holding a `NumberRange` (`range(): ?NumberRange`); `optionIds()`
  and `booleans()` return `[]` for it. Still `@internal`, built only by `resolve()`; the values list
  stays non-empty for choice/boolean. A selection is one per attribute.
- `resolve()`: for each requested range, keep it iff `$schema->facet($id)?->kind() === Number` and
  no selection exists yet for that attribute; values (`f`) on a Number facet fall to the existing
  `match`'s new `Number => []` arm (dropped). **No `NumberRules` input exists** to clamp with (S-12)
  — the schema does not even carry the bounds.
- `FacetedQuery` gains `price(): ?PriceRange`, passed through from the request, independent of the
  schema (applies with no category, Q7).

### D7 — The statements: no new one

Changes to search-browse D5's table, all inside `PostgresSearch`:

| # | Change |
|---|---|
| 2 facet schema | `a.type IN ('choice','boolean','number')` (mutation d); also selects `a.number_rules->>'unit' AS unit` (the only rules field read). |
| fragment | per range selection `i`: `AND EXISTS (SELECT 1 FROM listing_attribute_value f{i} WHERE f{i}.listing_id = l.id AND f{i}.attribute_id = :a{i} [AND f{i}.value_int >= :lo{i}] [AND f{i}.value_int <= :hi{i}])`, bounds bound as `ParameterType::INTEGER`, **no cast** (CLAUDE.md: `::bigint`, never `::int` — mutation i). Price predicate (D1) appended once, after `status`. Both live in `matching()` — S-1/S-2/S-6/S-11/S-13's one definition. |
| 5 / 6…5+k counts | `SELECT …, min(v.value_int) AS lo, max(v.value_int) AS hi` and filter `(o.id IS NOT NULL OR v.value_bool IS NOT NULL OR v.value_int IS NOT NULL)`. Number rows have `option_id`, `label`, `value_bool` all NULL, so the **existing `GROUP BY`** folds them into **one row per number attribute**: `count(*)` = listings with a value (PK is `(listing_id, attribute_id)`), `lo`/`hi` = observed range. Never add `value_int` to the `GROUP BY` (mutation t). A selected number facet gets its own statement with its range omitted (S-11, the existing `$omit` mechanism). |

Budget unchanged: ≤ 5 + k with a category (k ≤ 20 across all kinds), ≤ 3 without. Price adds a
predicate, not a statement.

### D8 — `RangeFacet` and `SearchResult`

`RangeFacet::of(FacetAttribute, int $count, ?int $min, ?int $max, FacetedQuery): ?self` — null when
count 0 and unselected (AC-6). `SearchResult::facets(): list<Facet|RangeFacet>` in schema display
order. `Facet::of()` gains no Number arm: the adapter routes number facets to `RangeFacet` (a
`Facet::of()` call with a Number attribute is the adapter's bug and throws `\LogicException`).
`FacetAttribute` gains `?string $unit` (null except number).

### D9 — Controller: shapes only, again (the `InputBag` footgun)

`$request->query->all()` read once (unchanged). New normalisers:

- `r`: not an array → `[]`; keep string keys whose value is an array; from it take `min`/`max` only
  when strings, else null → `array<string, array{min: ?string, max: ?string}>`.
- `price`: not an array → all null; take `currency`/`min`/`max` only when strings.

Never `all('r')`, `all('price')`, `get()`, `getInt()` (each 400s on a hostile shape — mutation p).
The link carrier grows: `f`, `r` (surviving ranges, `min`/`max` as strings, absent ends omitted) and
`price` (`currency` + minor units back to whole units: `intdiv(min, 10 ** minorUnits)`; exact,
because surviving bounds were whole units) are passed to every `path('app_browse', …)`.

### D10 — Template

- Form always rendered (price applies everywhere, Q7). Hidden `category` only when browsing one.
- Per `RangeFacet`: `<fieldset class="search-facet search-facet--range">`, `<legend>Label (unit)</legend>`,
  two `<input type="number" step="1" inputmode="numeric">` named `r[<id>][min]` / `[max]` with
  `value` from the surviving range, placeholders from the observed range, and `N listings, lo–hi`.
  **No `min`/`max` attributes** (S-12).
- One `<fieldset class="search-price">`: `<select name="price[currency]">` (empty *Any currency*, then
  the mirror's cases), `price[min]`/`price[max]` (`type="number" min="0" step="1"`), and the note
  *"Choosing a currency hides listings priced in other currencies."*
- Still unstyled, no `<script>`.

### D11 — No migration, no index

The count statement's `v.attribute_id IN (:facets)` already gives the generic index its `Index Cond`
for number facets too (AC-19). The range `EXISTS` is served by the PK `(listing_id, attribute_id)`.
`(attribute_id, value_int)` and `(price_currency, price_amount)` are `search-benchmark`'s call on
`ANALYZE`d data — and `listing_attribute_value` is invisible to Doctrine (unmapped, schema-filtered),
so any index there is a hand-written migration plus AC-6 (listing-attribute-values) update; that cost
is slice 4's to weigh. No expression index in any case (CLAUDE.md footgun).

### D12 — `UAH` leaves Listing's allow-list (owner, 2026-11-23) — task T1, before the mirror

The owner narrowed L-5: "we'll add other currencies later". A **Listing Domain** change, the slice's
only one outside Search:

- `Domain/Listing/ValueObject/Currency.php`: drop `case UAH`, its `minorUnits()` arm, and the docblock's
  "All four allowed currencies" → "All three". ADR-0019 d.7's promise holds the other way round:
  **re-adding a code is a code change and a test, never a migration**.
- `Infrastructure/Listing/Form/ListingFormData.php:27`: default `Currency::EUR->value` (owner's
  choice; it was `UAH`). The form's `ChoiceType` is built from `Currency::cases()`, so it offers three
  codes and refuses a tampered `UAH` with `UNSUPPORTED_CURRENCY` without further change.
- Tests: `SellTest` (≈243 default assertion → `'EUR'`; 412/421/493/503), `ListingHandlersTest`
  (110, 174, 224, 235, 267), `CurrencyTest` (18 → three cases; 35 → a refusal case for `'UAH'`),
  `PriceTest` (23, 88), `ListingTest` (131, 155, 164, 226): swap `UAH` for `SEK` (the remaining
  non-default code, so a test that wants "not the default" still means it). Add the refusal cases
  AC-28 names.
- **Data risk.** `price_currency` maps to the enum; a stored `'UAH'` row would fail to hydrate (500 +
  Sentry) on its page, its seller's list, and — via `PostgresSearch`'s `Currency::fromCode()` — on
  **every `/browse` page whose hits include it**. Guard: a **one-off pre-deploy query** in
  `docs/infrastructure.md` (§*Listing: one-off pre-deploy check for `search-range-facets`*, the
  catalog-schema-inheritance D10 style): `SELECT count(*) FROM listing_listing WHERE price_currency =
  'UAH'` on the box; must be **0**; otherwise the **owner** decides (re-price or delete) before deploy;
  the result is logged there (AC-29).
- **No database guard** (no CHECK, no Postgres enum type) — YAGNI: pre-launch, `/` is the
  coming-soon stub, the pre-deploy query covers the one moment rows could exist, and a CHECK would
  turn "re-adding a currency is never a migration" into a migration every time. The Domain enum stays
  the only allow-list; the column stays `varchar(3)`.

## Domain layer (pure PHP) — `src/Domain/Search/`

- **Aggregate / entity changes:** none (D2).
- **Value objects** (`ValueObject/`):
  - `FacetKind::Number = 'number'` (same backing string as Catalog's `AttributeType`).
  - `NumberRange` (D3). `Currency` (D4, mirror). `PriceRange(Currency, ?NumberRange)` — the price
    filter, bounds in minor units.
  - `FilterRequest` (D5), `Selection` (D6), `FacetedQuery` (D6), `FacetAttribute` (+`unit`).
  - Results: `RangeFacet` (D8); `SearchResult::facets()` widened.
- **Domain events:** none.
- **Ports:** `SearchPort` — signatures unchanged; docblocks extended (number facets; range and price
  semantics; S-12).

## Application layer — `src/Application/Search/`

- **Listing (D12):** no Application change — `ReviseListing`'s currency string is still parsed by
  `Currency::fromCode()`, which now refuses `'UAH'`.
- **Query:** `BrowseListings(?string $category, array $filters, array $ranges, array $price, int $page)`
  — every field required. Three call sites.
- **Handler:** unchanged flow; `FilterRequest::parse($q->filters, $q->ranges, $q->price)`.
- **Transaction / idempotency:** none (pure read, ADR-0023 d.5).

## Infrastructure layer — `src/Infrastructure/Search/`

- **Persistence:** `PostgresSearch` — D7; maps `unit` into `FacetAttribute`; builds `RangeFacet`s
  from the count rows' `lo`/`hi`. Docblock: S-12 ("no rules clause, on purpose"), price predicate
  in the fragment, never a cast on bounds.
- **HTTP / UI:** `BrowseController` (D9), `browse.html.twig` (D10).
- **DI / Deptrac / mapping / migration:** none.
- **Listing (D12):** `ListingFormData` default `EUR`; nothing else in `Infrastructure/Listing` names a code.

## Interface boundary & input contract

- **HTTP:** `GET /browse?category=<uuid>&f[<id>][]=<option|yes|no>&r[<id>][min]=<int>&r[<id>][max]=<int>&price[currency]=<USD|EUR|SEK>&price[min]=<int>&price[max]=<int>&page=<int>`
  — all optional. **200** for any `f`/`r`/`price`/`page`; **404** only for a bad category (unchanged).
- **Application:** `BrowseListings` as above; throws only `UnknownCategory`.
- **Domain:** SQL sees only resolved, bound integers and codes; parameter names from indexes only.

## Data & migrations

- **None** (D11, D12). One **pre-deploy query** (D12, AC-29) instead of a migration. New reads: `catalog_attribute.number_rules->>'unit'`,
  `listing_attribute_value.value_int`, `listing_listing.price_amount`, `price_currency` — all inside
  `Infrastructure/Search/`, ADR-0023's watch item covers them.

## Test plan

- **Domain unit** — `FilterRequestTest` (AC-1; existing calls updated), `FacetedQueryTest` (AC-2),
  `NumberRangeTest` (AC-3), `PriceRangeTest` (AC-4), `CurrencyMirrorTest` (AC-5), `RangeFacetTest`
  (AC-6), `SearchIsolationTest` (AC-7, unchanged).
- **Integration** — `tests/Support/BuildsTheRangeFixture` (feature-spec §B; on `BuildsTheValuesTree`,
  prices via `ListingFactory::pricedAt()`, Strings via `withValues()`); `BrowseListingsTest` gains
  AC-8…AC-18; `SearchIndexUsageTest` gains AC-19.
- **Functional** — `BrowsePageTest` gains AC-20…AC-24; **amended on purpose**: the
  no-category test now expects the form with only the price fieldset (AC-20).
- **Listing (D12)** — `CurrencyTest`, `PriceTest`, `ListingTest`, `ListingHandlersTest`, `SellTest`
  updated; refusal cases for `UAH` (AC-28).
- **Mutation proof** — AC-25 (a)…(w), each applied, run, reverted; catcher named in the commit.

## ADRs

- **ADR-0024 (new, T0):** *Price is compared only within one currency* — D1 (A over B/C/D, owner
  2026-11-23), Q1b, the future-sort rule, the revisit trigger (with the UAH-rates note), the
  three-code list, the Search-local `Currency` mirror + drift test.
- **ADR-0023 amendment:** number facets (S-3 widened), range selections in the one fragment (S-11),
  **S-12 — write rules are not re-enforced on reads**, enum mirrors count as local copies (fence 4),
  price predicate in the fragment.
- **ADR-0019 amendment:** **decision 7** — allow-list narrowed to `USD`, `EUR`, `SEK` (owner,
  2026-11-23; `UAH` removed, "we'll add other currencies later"); re-adding a code is a code change +
  test, never a migration; no DB guard (D12). Consequences' "cross-currency comparison is undecided"
  → answered by ADR-0024; "nothing filters on price" no longer true; "nothing sorts" still true.
- ADR-0004: no amendment needed (still zero promoted columns; slice 4 owns indexes).

## Risks / open questions

1. **A `UAH` row on the box** (D12) is the one way this slice can break prod at deploy — and it
   would break `/browse`, not only the row's own page. AC-29's query is run and logged **before**
   the deploy, never after.
2. **Existing tests that list facets.** Number facets now appear wherever a published listing holds a
   number value. The lifted facet fixture holds none (Strings is optional), so no existing assertion
   should move; if one does, the fixture — not the expectation — is checked first.
3. **URL noise:** an untouched JS-free form submits every blank `r[…][min|max]`. Correct (blank =
   absent) and harmless; `search-ui` can strip them.
4. **Exponent drift** is the only dangerous mirror drift (a ×100 bug); `CurrencyMirrorTest` is its
   sole guard — it must fail when either side changes (mutation l).
5. **A selected number facet adds a count statement** (disjunctive stats) — same cost model as a
   selected choice facet; slice 4 measures.
6. **pdo_pgsql and int64 binds:** verify a bound of `4999999999` round-trips without an `int4`
   coercion (AC-18) before writing the fragment; if PDO sends it typed wrong, bind as a string and let
   the `bigint` column decide — never add a cast.
