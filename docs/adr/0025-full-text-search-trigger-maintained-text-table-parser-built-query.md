# ADR-0025: Full-text search — a Search-owned text table kept by a database trigger; one fold for write and read; the parser builds the query

- **Status:** Accepted
- **Date:** 2026-11-23
- **Established by** the `search-full-text` slice ([spec](../specs/search-full-text/feature-spec.md)
  Q1–Q7, [plan](../specs/search-full-text/technical-plan.md) D2–D8; owner approved the
  recommendations, 2026-11-23) — Phase 2 item 3.
- **Replaces** [ADR-0023](./0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md)
  decision 2, fence point 5 ("No Search table, no Search migration"). The rest of ADR-0023 stands.
- **Implements** [ADR-0002](./0002-search-postgres-behind-port.md)'s free-text and text-hygiene
  bullets (see its 2026-11-23 amendment).

## Context

`/browse` gains a free-text `q` over listing title and description. ADR-0002 already chose the shape —
PostgreSQL FTS first, `pg_trgm` as a fallback, `unaccent` on both sides, a sanitizer that strips every
operator and puts `:*` on the last token only. What it left open, and what the pre-planning checks
(plan §Pre-planning) measured:

- **The vector needs a home, and every obvious one is wrong here.** A generated column or expression
  index on `listing_listing` is re-diffed by Doctrine forever (measured: an unmapped column → `DROP`),
  and is impossible anyway: `unaccent()` is `STABLE`. Having Listing's repository write a side table
  repeats the footgun that already bit attribute values — a Foundry `create()` or an EM flush persists
  nothing, silently. ADR-0023 fence 5 forbade a Search table outright.
- **The roadmap's literal sanitizer is injectable.** `unaccent.rules` maps some *letters* to tsquery
  syntax (`ʼ`→`'`, `ː`→`:`, `ŉ`→`'n`). So "sanitize in PHP, fold in SQL, then `to_tsquery`" turns
  `ʼfoo` into `syntax error in tsquery` — a 500 — after the sanitizer has already approved it, and
  `donʼt` silently into a phrase query. `plainto_tsquery` over the folded text survived every hostile
  input tried.
- **Sellers write in several languages**; titles are mostly brand + model + year.
- `unaccent` and `pg_trgm` are available and *trusted* in `postgres:16-alpine` (16.13); the migration
  role is the initdb superuser in dev and (by configuration) on the box.

## Decision

1. **A Search-owned side table, `search_listing_text (listing_id PK → listing_listing ON DELETE
   CASCADE, document tsvector, title_folded text)`, written only by a database trigger** —
   `AFTER INSERT OR UPDATE OF title, description ON listing_listing`, in the writer's transaction.
   Every write path (repository, Foundry, a hand `UPDATE`, a future import) keeps it in sync; there
   is no second writer to forget. Listing's code does not change and does not know Search exists.
   The table is hidden from Doctrine by `schema_filter`; the trigger and functions are assets
   Doctrine does not read, so nothing is ever re-diffed.
2. **Fence point 5 becomes:** *Search may own derived tables written only by the database, in the
   writer's transaction; Search's PHP still writes nothing.* Fence point 1 (`SearchIsolationTest`'s
   write-keyword scan) is unchanged.
3. **One fold, one query builder, both in SQL, both in one migration.** `search_fold(text)` is an
   `IMMUTABLE` wrapper over `public.unaccent('public.unaccent'::regdictionary, …)`, used by the
   trigger and the read. `search_tsquery(text)` = `plainto_tsquery('simple', search_fold($1))`, then
   `:*` appended to PostgreSQL's **own** canonical output, so only the last lexeme gets it.
4. **The sanitizer has two halves.** PHP `SearchTerms::parse()` (S-15) keeps only runs of letters,
   marks and digits — first 100 characters, at most 8 terms, invalid UTF-8 or nothing → no text
   filter. It never folds, lower-cases or appends `:*` (that would be a second mangling). SQL's
   `search_tsquery()` is the other half. `Infrastructure/Search` may not call `to_tsquery`,
   `plainto_tsquery`, `websearch_to_tsquery`, `phraseto_tsquery` or `unaccent` directly — a scan
   enforces it.
5. **Configuration `simple`** — no stemming, no stopwords: language-neutral and predictable for names.
6. **Trigram over the title only**, `title_folded %> search_fold(:q)` at the default
   `word_similarity_threshold` (0.6), never `SET` per request (it would leak into the pooled session).
7. **Fallback only when the exact match finds nothing** (`SearchTerms::FALLBACK_BELOW = 1`), decided
   once per request with every selection applied; then every statement uses that mode. A page is
   all-exact or all-similar.
8. **Free text is a filter, not a ranker.** Order stays newest first (S-8); no `ts_rank`, no weights.
   **No ranks combine, so RRF is not built.** Revisit trigger: a fallback threshold above 1, or a
   relevance sort.

## Alternatives

- **Generated column / expression index on `listing_listing`** — impossible (`unaccent` is `STABLE`)
  and re-diffed by Doctrine forever even if a wrapper made it legal.
- **Side table written by Listing's repository** — every write path must remember it; Listing's
  Infrastructure learns Search's fold and configuration. The drift ADR-0002 rejected Meilisearch to
  avoid, in miniature.
- **Side table maintained by listeners** (ADR-0023 option C) — Listing emits no revise event, and an
  async sync path is exactly that drift.
- **No index** (`to_tsvector` per row per request) — `search-benchmark` would fail on arrival.
- **PHP builds `a & b:*`, SQL runs `to_tsquery(search_fold(:q))`** — measured injectable (Context).
  **`websearch_to_tsquery`** — a visitor-facing query syntax (a non-goal) that still cannot add `:*`.
- **`english` configuration** — stems names, drops stopwords (`The Who`), mangles Swedish.

## Consequences

- **Easy:** one writer, one fold, one file to read for "how does a title become searchable". Rebuild
  is `UPDATE listing_listing SET title = title` — the backfill is the write path, so it cannot disagree.
- **Hard — logic in the database.** The table, functions and trigger are invisible to
  `schema:validate`, `migration.make` and PHP readers; a shape test and a behaviour (sync) test are
  their only guards, as for `listing_attribute_value`.
- **Watch item — the sharpest edge:** a rename or type change of `listing_listing.title` or
  `description` must redefine `search_listing_text_sync()` **in the same migration**. Otherwise the
  trigger names a missing column and **every listing write fails** at runtime.
- **Rebuild after a PostgreSQL major upgrade** (or any `unaccent.rules` change) — runbook entry.
- **Accepted recall costs:** no stemming, so a plural in a non-last term misses (`guitar fender` ≠
  "Fender guitars"); `5.1` is split into two terms; `å ä ö` fold to `a a o` on both sides.
- **The FK is not an inter-aggregate reference** (ADR-0009 governs aggregates; this row is a
  derivation of one). It is invisible to Doctrine because its table is filtered.
