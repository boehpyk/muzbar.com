# Task List: search-full-text

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/search-full-text`. Run `make check` before each commit. Check off as you go.
> Unit tests land **with** the Domain code they cover. **Owner answers:** 2026-11-23 — every recommendation (Q1–Q7) accepted.

## Decision
- [x] T0: `/adr` → **ADR-0025** *Full-text search: a Search-owned text index kept by a database
      trigger; one fold for write and read; the parser builds the query* (plan D2, D3, D4, D6, D7,
      D8; the pre-planning measurements — `ʼfoo` → `syntax error in tsquery`, the two Doctrine
      `DROP`s). States that it replaces ADR-0023 fence 5.

## Domain (`src/Domain/Search/`)
- [x] T1: `SearchTerms` — `parse()` (S-15: UTF-8 check, first 100 chars, split on
      `[^\p{L}\p{M}\p{N}]+`, drop runs without a letter/digit, first 8, `null` when none),
      `terms()`, `toString()`, `fallsBackAt()` + constants; docblock (why PHP does not fold; why the
      parser builds the query) + `SearchTermsTest` (AC-1, AC-2, AC-3, incl. the 1 000-input fuzz
      loop).
- [x] T2: `TextMatch` enum; `FilterRequest::parse(…, ?string $text)` + `text()`; `FacetedQuery::text()`
      (carried independent of the schema); `SearchResult::textMatch()`; every existing `parse()` /
      `SearchResult` call site updated mechanically; `FacetedQueryTest`/`FilterRequestTest` (AC-4).
      `SearchPort` docblock (S-17, S-18, newest first with text).

## Application (`src/Application/Search/`)
- [x] T3: `BrowseListings` gains `?string $text` (required); handler passes it to `parse()`. Lands
      **with** T7 (the controller is a call site) or with the controller passing `null` until T7.

## Infrastructure
- [x] T4: Migration (plan D5): extensions, `search_fold()`, `search_tsquery()`,
      `search_listing_text` + FK + two GIN indexes, `search_listing_text_sync()` + trigger, backfill by
      `UPDATE listing_listing SET title = title`; `down()` in reverse. `doctrine.yaml` `schema_filter`
      + comment. **With** `SearchTextSchemaTest` (AC-6) — the table's only shape guard lands with the
      table. Record AC-8 in the commit: `doctrine:schema:update --dump-sql` empty (dev + test), and
      the flip (filter entry removed → `DROP TABLE search_listing_text`, reverted).
- [x] T5: `SearchTextSyncTest` (AC-7): handler-created and Foundry-created drafts get a row; revise
      description / title update it; publish / withdraw leave it byte-identical; row count = listing
      count.
- [x] T6: `PostgresSearch` — `matching($query, $omit, TextMatch)` with the two predicates (plan D9,
      never skipped by `$omit`); the fallback sequence in `search()` (D7) via
      `SearchTerms::fallsBackAt()`; `textMatch` into `SearchResult`; docblock (S-16/17/18, only the two
      functions, no GUC `SET`, newest first).
- [x] T7: `BrowseController` — `q` shape (`is_string` or `null`, from the one `all()` read), link
      carrier `q` from the surviving terms; `browse.html.twig` — `<input type="search" name="q"
      maxlength=…>` in the always-rendered form, the similar-match notice (Q7 wording).

## Tests (qa)
- [x] T8: `tests/Support/BuildsTheTextFixture` (T1–T5, Tc, Td, Tw per feature-spec §C, T4 published
      last) + `FullTextSearchTest` AC-9…AC-15 (AC-10's corpus through the handler).
- [x] T9: `SearchIndexUsageTest` AC-16 — two recorded browses (`q=stratocaster`, `q=stratocster`),
      `ANALYZE search_listing_text` inside DAMA, `Index Cond` checks (`@@`, `%>`); add
      `enable_indexscan = off` to the trigram replay only if the planner picks a full pkey scan, and
      say why in the docblock.
- [x] T10: `BrowsePageTest` AC-17…AC-20 (`QueryRecordingStatement` for AC-19's statement count and
      function-name assertions).
- [x] T11: `SearchIsolationTest` — fail on `to_tsquery(`, `plainto_tsquery(`, `websearch_to_tsquery(`,
      `phraseto_tsquery(`, `unaccent(` in `Infrastructure/Search` code (comments stripped), with a
      self-test that the pattern bites; `search_listing_text` named only under `Infrastructure/Search`
      (asserting the scan found it at all, so it cannot pass vacuously).
- [x] T12: Mutation proof — AC-21 (a)…(u), each applied, run, reverted; catcher recorded in the
      commit message. `make test` twice; `php -d zend.assertions=-1 vendor/bin/phpunit`; drop
      `muzbar_test` and `make test.db` from scratch (AC-24). `make test filter=` breaks on `|` — run
      phpunit directly for alternations.

## Docs
- [x] T13: ADR-0023 amendment (fence 5 → ADR-0025; S-17 in the fragment; the new scans; the trigger
      watch item); ADR-0002 amendment (*too few* = none; RRF trigger; `simple`; the sanitizer's two
      halves).
- [ ] T14: `docs/infrastructure.md` §*Search: full-text — pre/post-deploy* (AC-22: the two box
      queries **run and logged before** the deploy, the post-deploy count, the rebuild statement and
      when; the restore-rehearsal note). CLAUDE.md — status paragraph; Search footguns: the trigger
      watch item (rename of `title`/`description`), `search_listing_text` invisible to Doctrine
      (AC-6 is its only shape guard), never `to_tsquery` on anything user-derived (the `ʼ` → `'`
      measurement), never `SET pg_trgm.*` per request, PHP never folds, the backfill/rebuild is
      `UPDATE … SET title = title`. Roadmap item 3 ticked with a "Shipped:" note.
- [ ] T15: `FORboehpyk.md` chapter — the derived value Doctrine cannot see; the letter that became an
      operator; filter vs ranker (and why that deletes RRF); bugs hit.

## Verify
- [ ] T16: `/verify` → reviewer PASS, AC-1…AC-24 checked, docs updated.
