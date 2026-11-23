# Task List: search-range-facets

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/search-range-facets`. Run `make check` before each commit. Check off as you go.
> Unit tests land **with** the Domain code they cover.
> **Owner answers 2026-11-23:** Q1 = A; Q1b, Q2–Q9 as recommended; Q10 — `UAH` leaves the allow-list
> (T1, before the mirror in T3).

## Decision
- [x] T0: `/adr` → **ADR-0024** *Price is compared only within one currency* (plan D1: A over
      B/C/D, owner 2026-11-23; Q1b; future-sort rule; revisit trigger with the UAH-rates note; the
      three-code list `USD`, `EUR`, `SEK`; the mirror).

## Listing (owner decision Q10 — before any Search code)
- [x] T1: `UAH` leaves the allow-list (plan D12): `Domain/Listing/ValueObject/Currency` (case,
      `minorUnits()` arm, docblock "All three"); `ListingFormData` default `EUR`; swap `UAH` → `SEK`
      in `SellTest`, `ListingHandlersTest`, `CurrencyTest`, `PriceTest`, `ListingTest` (the default
      assertion → `'EUR'`); add the AC-28 refusal cases (`fromCode('UAH')`, tampered form POST,
      `ReviseListing` with `'UAH'`); `docs/infrastructure.md` §*Listing: one-off pre-deploy check for
      `search-range-facets`* with the AC-29 query, expected `0`, and "non-zero → owner decides
      re-price or delete before deploy". ADR-0019 decision-7 amendment lands here too (no DB guard,
      and why).

## Domain (`src/Domain/Search/`)
- [x] T2: `NumberRange::of()` (null when empty, swap when inverted) + `NumberRangeTest` (AC-3).
- [x] T3: Search-local `Currency` enum — **three codes**, `minorUnits()` — + `PriceRange` +
      `CurrencyMirrorTest` (AC-5) + `PriceRangeTest` (AC-4).
- [x] T4: `FilterRequest::parse($values, $ranges, $price)` — bound/price formats, blank = absent,
      shared cap, `requestedRanges()`, `price()`; update the 18 existing call sites; extend
      `FilterRequestTest` (AC-1).
- [x] T5: `FacetKind::Number`; `FacetAttribute` gains `?unit`; `Selection` range form;
      `FacetedQuery::resolve()` keeps ranges on number facets only, drops `f` on them, carries
      `price()` + `FacetedQueryTest` (AC-2).
- [x] T6: `RangeFacet::of()`, `SearchResult::facets(): list<Facet|RangeFacet>`, `Facet::of()`
      refuses a number attribute (`\LogicException`) + `RangeFacetTest` (AC-6). `SearchPort`
      docblocks.

## Application (`src/Application/Search/`)
- [x] T7: `BrowseListings` gains `ranges`, `price` (required); handler passes them to `parse()`.
      Lands **with** T8 and T9 (the controller is a call site).

## Infrastructure (`src/Infrastructure/Search/`)
- [x] T8: `PostgresSearch` — `number` in `facetSchemaFor()` + unit; range `EXISTS` and price
      predicate in `matching()` (no casts); `lo`/`hi` + `value_int IS NOT NULL` in the count
      statement; `RangeFacet` assembly; docblock (S-12, one fragment, no cast).
- [x] T9: `BrowseController` — `r` / `price` shape normalisers (never `all('r')`), link carrier for
      `r` and `price`; `browse.html.twig` — form always rendered, range fieldsets (no `min`/`max`
      attributes), price fieldset (three codes) + note.

## Tests (qa)
- [x] T10: `tests/Support/BuildsTheRangeFixture` + `BrowseListingsTest` AC-8…AC-18 (verify plan
      Risk 6, the int64 bind, first).
- [x] T11: `SearchIndexUsageTest` AC-19 (recorded browse with a range and a choice selection).
- [x] T12: `BrowsePageTest` AC-20…AC-24; amend the no-category test (form with price only) and say
      so in the commit message.
- [ ] T13: Mutation proof — AC-25 (a)…(w), each applied, run, reverted; catcher recorded in the
      commit message. `make test` twice; `php -d zend.assertions=-1 vendor/bin/phpunit` (AC-27).
      `make test filter=` breaks on `|` — run phpunit directly for alternations.

## Docs
- [ ] T14: ADR-0023 amendment (number facets, ranges, S-12, mirrors as local copies, price in the
      fragment); ADR-0019 Consequences amendment (cross-currency clause → ADR-0024).
- [ ] T15: CLAUDE.md — status paragraph; Search footguns: S-12 (never clamp to `NumberRules` on a
      read), the `Currency` mirror + its test (add a currency in **both** enums), number stats ride
      the count statement (never `GROUP BY value_int`), blank = absent, no casts on bounds, price is
      one currency; the allow-list is `USD`/`EUR`/`SEK` and a removed code needs a pre-deploy row
      check. Roadmap item 2 ticked (and the listing-draft line's four-code list annotated, not
      rewritten). `docs/infrastructure.md`: the Q1 revisit-trigger query; the AC-29 result logged
      **before** the deploy.
- [ ] T16: `FORboehpyk.md` chapter — write rules are not read rules; "not comparable" as a domain
      fact; intervals that cannot be inverted; narrowing an enum is a data question, not a code
      question; bugs hit.

## Verify
- [ ] T17: `/verify` → reviewer PASS, AC-1…AC-29 checked, docs updated.
