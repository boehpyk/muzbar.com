# Task List: search-browse

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/search-browse`. Run `make check` before each commit. Check off as you go.
> Tests are listed after the code they cover but **land with it** where a task says so (a port with
> no adapter fails only at runtime — CLAUDE.md "Symfony compiles services lazily").

## Decision
- [x] T0: `/adr` → **ADR-0023** *Search is a read-only projection over Catalog's and Listing's
      tables* (plan D1: options A/B/C/D, recommendation A and its five-point fence; D2 no aggregate;
      D3/D4 port shape; D6 no lock; the watch item). Only after the owner answers Q1.

## Domain (`src/Domain/Search/`)
- [x] T1: `CategoryId`, `AttributeId`, `OptionId` (`UuidIdShape` + `malformed()` hook) and
      `InvalidCategoryId`, `InvalidAttributeId`, `InvalidOptionId`; add the three to
      `UuidIdShapeTest`'s ring. `FacetKind` enum. `UnknownCategory` exception.
- [x] T2: `FilterRequest::parse()` — format, dedupe, caps (S-5 first half) + `FilterRequestTest`
      (AC-1).
- [x] T3: `FacetAttribute`, `FacetSchema` (`none()`, `facet()`, `optionBelongsTo()`).
- [x] T4: `Selection`, `FacetedQuery::resolve()` — S-5 second half, page clamp (S-8) +
      `FacetedQueryTest` (AC-2, AC-3).
- [x] T5: Result types `SearchResult`, `ListingHit`, `FacetBucket`, `Facet::of()` + `FacetTest`
      (AC-4).
- [x] T6: `Port/SearchPort` (D3).

## Application (`src/Application/Search/`)
- [ ] T7: `Query/BrowseListings` + `Handler/BrowseListingsHandler` (four steps, plan §Application).
      Lands **with** T8 (the handler injects the port).

## Infrastructure (`src/Infrastructure/Search/`)
- [ ] T8: `Persistence/Doctrine/PostgresSearch` — matching fragment, `facetSchemaFor()` (statement 2),
      `search()` (statements 3–5+k), row mapping, `Price` formatting (D5–D7); DI alias in
      `config/services.yaml`. Class docblock: ADR-0023 fence, no lock, never `seller_id`.
      `ListingIsolationTest`: the two table-ownership scans admit `Infrastructure/Search/`.
      `SearchIsolationTest` (AC-5: context scan on Domain/Application; read-only and `seller_id`
      scans on `Infrastructure/Search`, each asserting > 0 files scanned).
- [ ] T9: `Http/Controller/BrowseController` (`GET /browse`, D8 normalisation, `UnknownCategory` →
      404, outline-derived breadcrumb/children/roots) + `templates/search/browse.html.twig` (noindex,
      stable classes, no script) + the nav link in `base.html.twig`.

## Tests (qa)
- [ ] T10: `QueryRecordingStatement` records bound values (D9; verify Risk 4 first). Existing users
      (`PublicListingTest`, `SellTest`, admin tests) unchanged and green.
- [ ] T11: Move the fixture: `tests/Support/BuildsTheFacetFixture` (from `SchemaMutationGateTest`
      section B); delete `FACET_COUNT`, sections B and C from `SchemaMutationGateTest` (D10).
      Lands **with** T12 and T13 so no assertion is ever absent from the suite (AC-26).
- [ ] T12: `tests/Integration/Search/BrowseListingsTest` — AC-6…AC-17 through the handler.
- [ ] T13: `tests/Integration/Search/SearchIndexUsageTest` — AC-18 (replayed EXPLAIN, `Index Cond`,
      no `Seq Scan`, docblock on what it does not prove).
- [ ] T14: `tests/Functional/Search/BrowsePageTest` — AC-19…AC-24 (anonymous; recorded SQL; 404
      parity; hostile parameters; statement bound with Brand of 2 vs 50; navigation keeps `f`).
- [ ] T15: Mutation proof — AC-25 (a)…(q), each applied, run, reverted; catcher recorded in the
      commit message. `make test` twice; `php -d zend.assertions=-1 vendor/bin/phpunit` (AC-28).
      Remember `make test filter=` breaks on `|` — run phpunit directly for alternations.

## Docs
- [ ] T16: ADR amendments, dated: ADR-0019 (decision 4 exception; crossings count stops), ADR-0002
      (port shape, discharged consequence), ADR-0004 (carry-overs discharged, `is_facet` deferred,
      zero promoted columns).
- [ ] T17: CLAUDE.md — status paragraph for `search-browse`; a **Search** block: ADR-0023's fence
      (read-only, one directory, never `seller_id`), the watch item (schema changes in
      `listing_*`/`catalog_*` and new "not public" predicates → grep `Infrastructure/Search/`, add to
      the matching fragment), `InputBag::all()`/`getInt()` 400 on hostile query strings (D8), no lock
      on reads (D6), the move of `FACET_COUNT`. Roadmap: item 1 ticked.
- [ ] T18: `FORboehpyk.md` — the chapter: a context with no aggregate; parse-don't-validate with two
      types; why a read model may join what aggregates keep apart; the `InputBag` 400 trap; bugs hit.

## Verify
- [ ] T19: `/verify` → reviewer PASS, AC-1…AC-28 checked, docs updated.
