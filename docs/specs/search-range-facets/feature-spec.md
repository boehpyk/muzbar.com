# Feature Spec: search-range-facets

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `search-range-facets` — Phase 2 item **2 of 15** ([roadmap](../../roadmap.md) §Phase 2).
  After `search-browse` (1); before `search-full-text` (3) and `search-benchmark` (4).
- **Bounded context(s):** **`Search`** (Domain, Application, Infrastructure) and **`Listing`**, by
  one owner decision only: **`UAH` leaves `Currency`'s allow-list** (owner, 2026-11-23 — "we'll add
  other currencies later"), and the sell form's default becomes `EUR`. No Listing mapping or
  migration change. **`Catalog`**: no change. Search reads
  `catalog_attribute.number_rules`, `listing_attribute_value.value_int` and
  `listing_listing.price_amount/price_currency` under ADR-0023's fence, unchanged.
- **Related PRD items:** US-2 / FR-3 (facets resolve "down to specific structural attributes" —
  string count, weight, scale length), §6 UX (guests search without signing up). Latency: §8 /
  Constitution §7, **measured in slice 4**, not here.
- **Governing ADRs:** [ADR-0023](../../adr/0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md)
  (read-only projection, one matching fragment, no lock), [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (money is integer minor units; **Consequences: "cross-currency comparison is undecided … until
  then, nothing sorts or filters on price"** — this slice owes that answer),
  [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (generic value table, zero promoted
  columns), [ADR-0017](../../adr/0017-attribute-schema-attributes-and-options-as-separate-aggregates.md)
  (number attributes: whole numbers, optional unit and bounds — C-16),
  [ADR-0021](../../adr/0021-listing-lifecycle-in-the-aggregate-completeness-on-public-writes-dispatch-after-commit.md)
  (number rules bite only on writes — **never retroactive**), [ADR-0022](../../adr/0022-schema-drift-archive-reserved-names-dormant-values.md)
  (archived attribute → dormant value → counts nowhere).
- **Status:** Approved by owner 2026-11-23 (Q1 = A; Q1b, Q2–Q9 as recommended; UAH removed from the
  allow-list, sell-form default EUR)
- **Date:** 2026-11-23

> **What this slice teaches, beyond the feature.**
>
> 1. **A rule enforced on writes must not be re-enforced on reads.** `NumberRules` bounds bite when
>    a seller saves (L-13); narrowing them later leaves stored values outside the new bounds, on
>    purpose (ADR-0021: never retroactive). If Search clamped or dropped a visitor's range at the
>    *current* bounds, those listings would become unfindable by the very filter that describes
>    them — retroactivity smuggled in through the read side. The bounds are therefore *not* a
>    resolution rule here (S-12). "Against `NumberRules`" means: the facet is a number attribute of
>    this category, and its unit labels it.
> 2. **"Not comparable" is a domain fact, and the honest model keeps it.** `Price` deliberately has
>    no `greaterThan()` across currencies. A price filter therefore lives *inside* one currency
>    (Q1 = A) instead of inventing an exchange rate the business does not own.
> 3. **Parse, don't validate — now for intervals.** `NumberRange` cannot be empty and cannot be
>    inverted, so no SQL is ever written for "min > max". The same two-step pipeline
>    (`FilterRequest` → `FacetedQuery::resolve()`) takes ranges without a new port method.

---

## Ubiquitous language (this slice)

Terms from `search-browse` (browse, subtree, facet, bucket, count, selection, filter request, faceted
query, facet schema, dropped, hit) keep their meaning. **Facet** widens to include number attributes.

| Term | Means | Not to be confused with |
|---|---|---|
| **Number facet** | An **active `number`** attribute in the browsed category's lineage (S-3 widened). Shown as a *from / to* pair, with its unit, the count of matching listings holding a value, and their observed range. | `NumberRules` (the admin's bounds — a write rule, not a filter). |
| **Range** (`NumberRange`) | An inclusive interval of whole numbers with at least one end: `min ≤ x`, `x ≤ max`, or both. Never empty, never inverted (an inverted request is **swapped**, Q3). | A bucket (ranges have none). |
| **Range selection** | A range on one number facet, resolved (S-10). A listing matches when it holds a value for that attribute **inside** the range; a listing with no value never matches. | `NumberRules` bounds. |
| **Observed range** | The smallest and largest value of a number facet among the listings matching every **other** selection (disjunctive, like a count — S-11). A display hint, never a limit on what may be typed. | The rules' bounds. |
| **Price filter** (`PriceRange`) | A **currency** plus an optional range in that currency's **minor units**. Restricts the browse to listings **priced in that currency** and, when bounds are given, within them (S-13, Q1). | Conversion (none exists). |
| **Whole units** | A price bound as typed: a whole number of the currency's major unit (`100` = 100.00 EUR). Turned into minor units by the currency's exponent (`× 10^minorUnits`), never through a float. | Minor units (what is stored and compared). |
| **Blank** | An empty string from a form field nobody filled in: *absent*, not zero. | `0` (a real bound). |

### Invariants

Search's series continues from S-9. Each is a **Domain rule** (pure function, unit-tested) or a
**query rule** (a SQL clause with a fixture row only it excludes).

| # | Rule | Where it lives | Proved by |
|---|---|---|---|
| **S-3 (widened)** | A facet is an active `choice`, `boolean` **or `number`** attribute in the browsed category's lineage. Text never. | Query: `facetSchemaFor()`'s `a.type IN (…)`. | AC-9, AC-14. |
| **S-10** | **A range selection survives only if** its attribute is a **number** facet of the browsed category and at least one bound parsed. A range on a choice/boolean facet is dropped; a choice/boolean value (`f[…][]`) on a number facet is dropped; a bound that is not `-?[0-9]{1,18}` is dropped (decimals, `+5`, `" 5"`, `1e3`, blank); a range left with no bound is dropped whole. An inverted range is **swapped**. The attribute's `NumberRules` bounds play **no** part. | **Domain:** `FilterRequest::parse()` (format), `NumberRange::of()` (swap), `FacetedQuery::resolve()` (kind). | AC-1, AC-2, AC-3, AC-13. |
| **S-11** | A range selection matches a listing iff it holds a value for that attribute with `min ≤ value ≤ max` (each end only when given). Ranges AND with every other selection (S-6). A number facet's **count and observed range are disjunctive**: they ignore that facet's own range and apply every other selection and the price filter. | Query: one `EXISTS (… value_int >= :lo AND value_int <= :hi)` per range in the matching fragment; the facet's stats come from the existing count statement. | AC-10, AC-11, AC-12. |
| **S-12** | **Stored values outside the attribute's current `NumberRules` stay findable.** Bounds narrowed after a value was stored neither drop, clamp nor hide anything on a read (ADR-0021: never retroactive). | The absence of any rules clause; proved by a fixture. | AC-15. |
| **S-13** | **Price is compared only within one currency** (Q1 = A). A price filter survives iff its currency is in the allow-list (`USD`, `EUR`, `SEK` — the Search-local mirror of Listing's `Currency`); bounds are whole units `[0-9]{1,7}`, converted to minor units; bounds **without** a valid currency are dropped whole (Q1b). It applies with or without a browsed category, to the total, the hits **and every facet count** — it is no facet's "own" selection. | **Domain:** `FilterRequest::parse()`, `PriceRange`. Query: one predicate in the matching fragment. | AC-1, AC-4, AC-16, AC-17. |
| **S-14** | Search's currency list and minor-unit exponents equal Listing's `Currency`, case by case. | Unit test reading both enums. | AC-5. |
| **L-5 (narrowed)** | Listing's `Currency` allow-list is **`USD`, `EUR`, `SEK`** (owner, 2026-11-23; was four with `UAH`). Adding a code back is a code change and a test, never a migration (ADR-0019 d.7). | **Domain:** `Listing\ValueObject\Currency`. | AC-28. |

**What these keep true together:** every number on the page still counts published, in-subtree
listings holding a value in force; a range or price that reaches SQL is non-empty, non-inverted,
integer, in minor units where money, and in one currency. **What is deliberately not kept true:** that
a typed bound lies within the attribute's current rules (S-12), or that prices in different currencies
are comparable (S-13).

## User story

As a **guest buyer**, I want to **narrow a category by a numeric detail (e.g. 7–8 strings) and by a
price range in my currency**, seeing how many listings hold each number and what range they span, so
that **I reach the configuration I can afford without signing up** — and the owner gets the
price-across-currencies decision made explicitly, before anything sorts or indexes on price.

## In scope

- `Search` Domain: `FacetKind::Number`; `NumberRange`; Search-local `Currency` enum (mirror,
  `minorUnits()`); `PriceRange`; `FilterRequest::parse()` gains ranges and price; `Selection` gains a
  range form; `FacetedQuery` resolves ranges (S-10) and carries the price filter; `FacetAttribute`
  gains `?unit`; result type `RangeFacet` (count, observed min/max, selected range).
- `Search` Application: `BrowseListings` gains `ranges` and `price` (required fields).
- `Search` Infrastructure: `PostgresSearch` — number attributes in `facetSchemaFor()`, range and price
  predicates in **the one matching fragment**, number stats in **the existing count statement** (no
  new statement); `BrowseController` normalises `r` and `price` (shapes only) and carries them on every
  link; `browse.html.twig` gains a number fieldset per number facet and one price fieldset (the form is
  now always rendered, because price applies at "All categories" too).
- **Listing (owner, 2026-11-23):** `Currency` loses `UAH` (case, `minorUnits()` arm, docblock); the
  sell form (`ListingFormData`) defaults to `EUR`; Listing tests that used `UAH` move to another
  code. Done **first** (T1), so Search's mirror is born with three codes. A one-off **pre-deploy
  query** (`docs/infrastructure.md` §*Listing*) proves no stored row says `UAH`.
- ADR-0024 (new) for Q1; amendments to ADR-0023 and ADR-0019 (plan §ADRs).

## Non-goals (explicit — hold the line)

- **Sorting** of any kind (price or otherwise) — hits stay newest first. ADR-0024 states the *rule*
  a future price sort must follow (only within a chosen currency); building it is `search-ui` (7)'s
  or later.
- **Currency conversion, exchange rates, a rates table, a rates job** (Q1 options B–D).
- **A database guard on `price_currency`** (CHECK or enum type) — plan D12: pre-launch, the
  pre-deploy query is the guard, and a CHECK would make "adding a currency back is never a
  migration" false. **Re-adding `UAH`** or any other code — a later owner decision.
- **Decimal number attributes** — `NumberRules` is whole numbers (owner, catalog-attribute-schema
  Q1); `value_int` is `bigint`. **Decimal price bounds** (`99.99`) — whole units only (Q6).
- **Any index or migration**, including `(price_currency, price_amount)` and
  `(attribute_id, value_int)` — `search-benchmark` (4) decides on `ANALYZE`d data. ADR-0019's
  "decide before `price_amount` is indexed" is satisfied by deciding *now*; indexing stays slice 4's.
- **Price statistics** (observed min/max per currency), **histograms / bucketed ranges**, sliders,
  "any value / no value" toggles → `search-ui` (7) if ever.
- **Styling, JS, live updates**, compacting blank `r[…]` fields out of the URL → `public-styles` (5) /
  `search-ui` (7).
- **Free text** → slice 3. **Rate limiting `/browse`** → slice 4's carry-over.
- Using `NumberRules` bounds as HTML `min`/`max` attributes (would block S-12's legitimate searches).

## Acceptance criteria (the Definition of Done checklist)

### A. The Domain rules (unit, no kernel)

- [ ] **AC-1 (`FilterRequest::parse()` — format)** — `parse(array $values, array $ranges, array $price)`:
      - ranges `array<string, array{min: ?string, max: ?string}>`: an attribute key that is not a
        well-formed UUID is dropped; a bound survives only as `^-?[0-9]{1,18}$` (no `u`, `D` anchor):
        `"10"`, `"-3"`, `"007"` → 10, −3, 7; `"10.5"`, `"+5"`, `" 5"`, `"5 "`, `"1e3"`, `""`,
        `"5\n"`, `"9999999999999999999"` dropped; a range with no surviving bound is dropped;
      - price `array{currency: ?string, min: ?string, max: ?string}`: currency survives only as an
        exact upper-case code of the mirror (`eur`, `EUR `, `GBP` dropped); a bound survives only as
        `^[0-9]{1,7}$` and becomes `bound × 10^minorUnits` (`"100"` EUR → 10000); with no surviving
        currency the whole price filter is dropped, bounds or not; a currency with no bounds survives;
      - the attribute cap `MAX_ATTRIBUTES = 20` counts **distinct attribute ids across `f` and `r`
        together**; the first 20 in request order (values first, then ranges) survive.
      Nothing throws. `search-browse`'s AC-1 cases stay green.
- [ ] **AC-2 (`FacetedQuery::resolve()` — S-10)** — Against a hand-built `FacetSchema`: a range on a
      number facet survives; on a choice or boolean facet, or an attribute not in the schema, it is
      dropped; values (`f`) on a number facet are dropped; one attribute yields **at most one**
      selection; with no category every range is dropped and **the price filter survives**.
- [ ] **AC-3 (`NumberRange`)** — `of(null, null)` is null; `of(5, null)`, `of(null, 5)`, `of(5, 5)`
      keep their ends; `of(20, 10)` is `10…20`; `of(PHP_INT_MIN, PHP_INT_MAX)` survives.
- [ ] **AC-4 (`PriceRange`)** — holds a `Currency` and an optional `NumberRange` in minor units;
      inverted bounds swapped (`min=500, max=100` → 10000…50000).
- [ ] **AC-5 (S-14, the mirror)** — `CurrencyMirrorTest`: Search's `Currency::cases()` values equal
      Listing's in order, and `minorUnits()` equals case by case. Removing a case or changing an
      exponent on either side fails it.
- [ ] **AC-6 (`RangeFacet::of()`)** — from a number `FacetAttribute`, `count`, `min`, `max` and the
      query: count 0 and no selection → null; count 0 **with** a selection → kept (so it can be
      cleared), observed range null; otherwise carries unit, count, observed min/max and the selected
      range.
- [ ] **AC-7 (isolation)** — `SearchIsolationTest` unchanged and green: the mirror `Currency` is
      Search's own (no `App\Domain\Listing` import in `Domain/Search` or `Application/Search`);
      `Infrastructure/Search` still has no write verb and no `seller_id`.

### B. Integration (DAMA, through `BrowseListingsHandler`)

Own fixture (`BuildsTheRangeFixture`, on `BuildsTheValuesTree`): published Electric listings
**N6a** Strings 6, Fender, EUR 300.00; **N6b** Strings 6, Gibson, EUR 1 200.00; **N12** Strings 12,
Fender, USD 300.00; **N0** no Strings value, Gibson, EUR 300.00; **Dn** draft, Strings 7; **Wn**
withdrawn, Strings 8; and **Bb** published on Bass, Bass strings 5 000 000 000 (no bounds), SEK
300.00. Every listing holds the required Condition (and Brand on Electric).

- [ ] **AC-8 (S-1/S-2 still hold for ranges)** — At Electric `r[Strings][min]=1` totals 3 (N6a, N6b,
      N12): Dn and Wn never counted, Bb never (other subtree).
- [ ] **AC-9 (S-3 widened)** — At Electric, Strings is a facet with unit `pcs`, count 3, observed
      `6…12`; at Guitars it is **not** a facet (defined on Electric, not in Guitars' lineage) and its
      `r` is dropped (total = unfiltered total). Text attribute Serial is never a facet.
- [ ] **AC-10 (S-11, inclusive and half-open)** — At Electric: `min=6,max=6` → 2; `min=7` → 1 (N12);
      `max=6` → 2; `min=6,max=12` → 3; `min=13` → 0.
- [ ] **AC-11 (no value never matches)** — N0 is in the unfiltered total and in **no** Strings range,
      including `min=-9999999` and `max=9999999`.
- [ ] **AC-12 (S-11, disjunctive stats)** — At Electric with `r[Strings][min]=7` and Brand = Fender
      (held by N6a, N12 only): total 1; Strings still shows count **2**, observed `6…12` (own range
      ignored, Brand applied); Brand's buckets apply the Strings range.
- [ ] **AC-13 (S-10 end to end)** — A request mixing one good range with each bad kind (malformed
      key, unknown uuid, range on Brand, range on Has case (boolean), range on Serial (text), `f[Strings][]=6`,
      bound `6.5`, blank both ends, attribute of the Bass branch) returns **exactly** the good range
      alone; an inverted `min=12,max=6` returns the same as `min=6,max=12`.
- [ ] **AC-14 (archived number attribute)** — After `ArchiveAttributeHandler` on Strings: no Strings
      facet at Electric, `r[Strings]` dropped (total unfiltered), stored value rows byte-identical.
- [ ] **AC-15 (S-12, never retroactive on reads)** — Narrow Strings to `4…10` through
      `EditAttributeHandler`: `r[Strings][min]=11` still finds N12, the observed range is still
      `6…12`, and `min=0` (below the rules) is not dropped (total 3).
- [ ] **AC-16 (S-13, one currency)** — At Electric: `price[currency]=EUR` totals 3 (N6a, N6b, N0);
      `EUR, max=300` totals 2 (N6a, N0; 300.00 inclusive); `EUR, min=301` totals 1; `USD, max=300` 1
      (N12) — **the same `300` in EUR and USD selects different listings**; `price[min]=100` without a
      currency is dropped (total unfiltered). With no category, `SEK` totals 1 (Bb).
- [ ] **AC-17 (price applies to every count)** — With `price[currency]=USD`, Brand buckets and the
      Strings facet count only N12 (count 1, observed `12…12`).
- [ ] **AC-18 (int64 values, no `int4` cast)** — At Bass, `r[Bass strings][min]=4999999999` totals 1
      and the observed range is `5000000000…5000000000`.

### C. The index (usability, not latency — slice 4)

- [ ] **AC-19 (EXPLAIN on the real statements)** — `SearchIndexUsageTest` gains a recorded browse at
      Electric with `r[Strings][min]=1` **and** Brand = Gibson: every count statement (now including
      the one with Strings' range omitted) has a node on `idx_listing_attribute_value_option` whose
      `Index Cond` contains `attribute_id =`; the hits statement with the range has **no** `Seq Scan`
      on `listing_attribute_value`. The docblock says no claim is made about `listing_listing`'s
      price predicate (unindexed; slice 4).

### D. The page (functional, `KernelBrowser`)

- [ ] **AC-20 (renders without JS)** — At Electric the GET form has a `<fieldset>` for Strings
      (`<legend>` with label and unit) holding `<input type="number" step="1" name="r[<id>][min]">`
      and `…[max]`, each carrying the surviving bound as `value`, and the text `3 listings, 6–12`; one
      price `<fieldset>` with `<select name="price[currency]">` (first option empty "Any currency",
      then USD, EUR, SEK in enum order — exactly three, the surviving one `selected`), `price[min]`/`price[max]`
      (`type="number" min="0" step="1"`), and a note that listings in other currencies are hidden
      when one is chosen. **No `min`/`max` HTML attribute on a Strings input.** `/browse` with no
      category now renders the form with the price fieldset only (search-browse's
      *no-facet-form* test is amended **on purpose**, AC-24).
- [ ] **AC-21 (blank is absent)** — Submitting the form untouched (every number input blank, currency
      empty) answers the same total as no filter; `r[<Strings>][min]=&r[<Strings>][max]=12` filters on
      max only.
- [ ] **AC-22 (hostile parameters are a 200)** — Each of `?r=x`, `?r[]=x`, `?r[<uuid>]=x`,
      `?r[<uuid>][min][]=1`, `?r[<uuid>][min]=%00`, `?price=x`, `?price[]=EUR`,
      `?price[currency][]=EUR`, `?price[currency]=UAH`, `?price[min]=-5`, `?price[min]=99999999`, a 30-attribute `r`, and
      invalid UTF-8 in keys and values answers **200** with the dropped parts ignored.
- [ ] **AC-23 (bounded statements)** — With `k` = surviving selections **of any kind** (choice,
      boolean, range), the GET sends at most `5 + k` statements; a price filter adds **none**; number
      facets add **none** (their stats ride the existing count statement); no category with a price
      filter sends at most 3. No statement is a write; none names `seller_id`.
- [ ] **AC-24 (links keep ranges and price)** — *Previous/Next*, breadcrumb and child links carry the
      surviving `r` and `price`; from Electric (Strings `min=6`, EUR) to Guitars the range is dropped
      (not in lineage) and the price filter kept — both by S-10/S-13, no special code. *Clear filters*
      clears all three.

### E. Proof and docs

- [ ] **AC-25 (tests bite)** — Each mutation fails a named test (recorded in the commit message):
  - (a) `>` instead of `>=` in the range predicate → AC-10
  - (b) the range `EXISTS` left out of the matching fragment → AC-10, AC-13
  - (c) the facet's own range applied to its own stats → AC-12
  - (d) `a.type IN ('choice','boolean')` not widened → AC-9
  - (e) `resolve()` keeps a range on a choice facet → AC-2, AC-13
  - (f) `resolve()` keeps `f` values on a number facet → AC-2
  - (g) no swap in `NumberRange::of()` → AC-3, AC-13
  - (h) the bound pattern without the `D` anchor or with `trim()` → AC-1
  - (i) `::int` cast on a range bound or on `value_int` → AC-18
  - (j) the `price_currency` predicate dropped (amount only) → AC-16
  - (k) whole units not multiplied by `10^minorUnits` → AC-16
  - (l) the mirror loses `SEK` (or an exponent changes) → AC-5
  - (m) `resolve()` drops or clamps a bound outside `NumberRules` → AC-15
  - (n) `v.value_int IS NOT NULL` missing from the count statement's filter → AC-9
  - (o) a range matched by `NOT EXISTS (… outside the range)` (no value matches) → AC-11
  - (p) `$request->query->all('r')` / `all('price')` in the controller → AC-22
  - (q) a blank bound read as `0` → AC-21
  - (r) a price filter kept without a currency → AC-1, AC-16
  - (s) the price predicate applied to total/hits only, not to the count statements → AC-17
  - (t) `v.value_int` added to the count statement's `GROUP BY` → AC-9, AC-12
  - (u) the cap counting `f` and `r` separately → AC-1
  - (v) `UAH` kept in Listing's `Currency` (and the mirror) → AC-28
  - (w) the sell form's default left on another code than `EUR` → AC-28
- [ ] **AC-26 (docs)** — ADR-0024 accepted (Q1's answer, the three-code list); dated amendments on
      ADR-0023 (number facets, range selections, S-12, the mirror as a local copy, price in the
      fragment) and ADR-0019 (decision 7: allow-list narrowed to `USD`, `EUR`, `SEK`, owner
      2026-11-23, re-adding is a code change + test, never a migration; Consequences' cross-currency
      clause answered → ADR-0024); the AC-29 pre-deploy query in `docs/infrastructure.md`; CLAUDE.md status + Search
      footguns; roadmap item 2 ticked; FORboehpyk.md chapter.
- [ ] **AC-27 (gates)** — `make check` green; `php -d zend.assertions=-1 vendor/bin/phpunit` green;
      `make test` twice in a row green.

### F. `UAH` leaves the allow-list (owner, 2026-11-23)

- [ ] **AC-28 (L-5 narrowed)** — Listing's `Currency::cases()` is exactly `USD, EUR, SEK`;
      `Currency::fromCode('UAH')` throws `InvalidPrice` (unsupported currency); the sell form renders
      `EUR` selected by default and offers exactly those three; a tampered `currency=UAH` POST to
      `/sell` or the edit page is refused by the form's `ChoiceType` (built from `Currency::cases()`)
      with `ListingFormType::UNSUPPORTED_CURRENCY` and writes nothing; `ReviseListing` with `'UAH'`
      through the handler throws `InvalidPrice` (the Domain refusal, which no HTTP test can reach). Every Listing test that used `UAH` uses another code (`SellTest`,
      `ListingHandlersTest`, `CurrencyTest`, `PriceTest`, `ListingTest`); none still names `UAH`
      except the refusal cases.
- [ ] **AC-29 (pre-deploy check)** — `docs/infrastructure.md` §*Listing: one-off pre-deploy check for `search-range-facets`*
      holds `SELECT count(*) FROM listing_listing WHERE price_currency = 'UAH'` (the
      catalog-schema-inheritance D10 style: what to run, where, the expected `0`, and what a
      non-zero means — **the owner decides** re-price or delete *before* deploying; a surviving row
      would 500 its own page, every "Your listings" page holding it, and **every `/browse` page whose
      hits include it**). The result on the box is logged there before the deploy.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| `r` / `price` not an array, nested wrong, keys/values malformed, over the cap | Dropped silently (S-10, S-13); 200 (AC-22). The form shows only what survived. |
| Range on an unknown, archived, out-of-lineage, choice, boolean or text attribute | Dropped (S-10). |
| Inverted range (`min > max`) | Swapped; the form shows the swapped bounds. |
| Range bound outside the attribute's current `NumberRules` | Kept (S-12). |
| Blank form fields | Absent, never zero (AC-21). |
| Price bounds without a currency, or an unknown currency (incl. `UAH`) | The whole price filter is dropped (Q1b); 200. |
| A `listing_listing` row with `price_currency = 'UAH'` after deploy | Hydration fails → 500 + Sentry on every read of it (its page, its seller's list, any `/browse` page showing it). Prevented by AC-29 — never deploy with a non-zero count. |
| A tampered `currency=UAH` on a Listing write | Refused (form: `UNSUPPORTED_CURRENCY`; Domain: `InvalidPrice`), nothing written (AC-28). |
| Negative, decimal or > 7-digit price bound | That bound is dropped; the other may survive. |
| A number facet with no matching value and no range selected | Not rendered. With a range selected: rendered with count 0 so it can be cleared. |
| Admin narrows/archives between the schema read and the count read | One stale page view (ADR-0023 d.5). No error. |
| PostgreSQL down | 500 → Sentry, as before. |
| Latency | Not measured here — slice 4. |

## Owner decisions (answered 2026-11-23)

All answered by the owner on 2026-11-23: **Q1 = A**; Q1b and Q2–Q9 **as recommended**; plus Q10 (new).
The text below is kept as the record of what was weighed.

1. **Price across currencies — ANSWERED: A** (owner, 2026-11-23). At planning time `Currency` had
   four cases; with Q10 it has **three** — `USD`, `EUR`, `SEK` — still more than one, so the decision
   is not hypothetical. Options (plan D1):
   - **A. Filter within one chosen currency, no conversion** *(chosen)*. Choosing a currency
     hides listings priced in others; bounds are in that currency. Zero new moving parts, honest to
     `Price` (no cross-currency order exists), indexable later as `(price_currency, price_amount)`.
     Cost: a EUR buyer does not see a SEK listing at an equivalent price while filtering.
   - **B. Convert with an owner-maintained static rate table** (config). Simple, but rates go stale
     silently — a filter that is wrong by the drift, with no signal.
   - **C. Convert with daily rates fetched from a public source** (e.g. the ECB's euro reference
     rates): a recurring job (re-opens ADR-0012 d.5), a Search-owned rates table (ADR-0023 fence
     point 5 → new ADR), a conversion join in every statement that no plain index serves, and a
     failure mode (stale/missing rate) to design.
   - **D. Store a normalised (e.g. EUR) amount at write time.** A Listing column fixed at the rate of
     the day the seller saved — wrong from the next day, and a Listing change for a Search need.
   **Trigger to revisit A:** after launch, when one top-level category holds **≥ 2 currencies each
   with ≥ 20 % of its published listings** (one SQL query, added to the runbook), or when a
   cross-currency price sort is asked for. Then C, as its own ADR. *Note for when `UAH` returns
   (Q10): the ECB's reference rates are believed not to include UAH (verify then), so C would need a
   second source, e.g. the National Bank of Ukraine.*
   **Q1b — ANSWERED: drop** (owner, 2026-11-23). Price bounds typed without a currency are dropped
   (S-5's "silently omitted"; the emptied inputs show it). Rejected: default to EUR.
2. **URL shape.** **ANSWERED as recommended** (owner, 2026-11-23): `r[<attribute uuid>][min]=6&r[<attribute uuid>][max]=8` and
   `price[currency]=EUR&price[min]=100&price[max]=500` — beside `f`, the whole query stays in the
   query string. Alternative: fold ranges into `f[<id>][min]` (mixes list and map shapes under one
   key; every `f` parser gains a branch).
3. **Inverted range.** **ANSWERED as recommended** (owner, 2026-11-23): **swap** (the visitor obviously meant the interval; the form shows
   it corrected). Alternative: drop it.
4. **`NumberRules` bounds on reads.** **ANSWERED as recommended** (owner, 2026-11-23): **ignored** (S-12, never retroactive; the unit is
   shown). Alternatives: drop out-of-bounds input, or clamp it — either hides listings stored under
   looser rules.
5. **What a number facet shows.** **ANSWERED as recommended** (owner, 2026-11-23): unit + count of matching listings with a value +
   observed `min–max`, disjunctive, hidden when 0 and unselected — **zero extra statements** (it rides
   the existing count statement). Alternatives: inputs only (no information, always shown, even when
   no listing has a value); bucketed counts (a histogram — needs bucket design, `search-ui`).
6. **Price bound precision.** **ANSWERED as recommended** (owner, 2026-11-23): whole units (`type="number" step="1"` enforces it without
   JS; a crafted `99.99` is dropped). Alternative: decimals — needs a second decimal parser inside
   Search (Listing's `Price::fromDecimal` is out of reach of Search's Domain) or a meaning decided in
   the controller.
7. **Price at "All categories".** **ANSWERED as recommended** (owner, 2026-11-23): yes — price has no schema dependency, so it is the one
   filter that works without a category (the form now always renders). Alternative: price only with
   a category.
8. **Price statistics** (observed min/max in the chosen currency). **ANSWERED as recommended** (owner, 2026-11-23): not now (an extra
   statement; `search-ui`).
9. **Cap.** **ANSWERED as recommended** (owner, 2026-11-23): `MAX_ATTRIBUTES = 20` across `f` and `r` together, so `k ≤ 20` and the
   `5 + k` bound holds unchanged. Alternative: separate caps (k ≤ 40).
10. **(New, owner, 2026-11-23) — `UAH` leaves the allow-list** ("we'll add other currencies later").
    Allow-list becomes `USD`, `EUR`, `SEK`; the sell form defaults to **`EUR`**. Done as T1, before
    Search's mirror exists. Guarded by the AC-29 pre-deploy query; no database guard (plan D12).
