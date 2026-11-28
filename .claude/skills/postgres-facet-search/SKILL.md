---
name: postgres-facet-search
description: How to add or extend a faceted-search filter on muzbar using PostgreSQL behind the SearchPort — schema-layer attributes vs hot-column promotion, index choice, the EXPLAIN check against the 200 ms budget, and FTS/pg_trgm text hygiene. Use when working on search, facets, or the category/attribute schema.
---

# Faceted search on Postgres (behind `SearchPort`)

muzbar searches on PostgreSQL, not a search engine (ADR-0002). All search access goes through the
Domain `SearchPort`; the Postgres adapter lives in `Infrastructure`. Never query the index from a
handler directly.

## Adding a facet
1. **Is it admin-defined?** Then it is data, not schema: an admin creates the `attribute`
   (+ `attribute_option`s) at runtime and listings store values in `listing_attribute_value`. The
   **generic** composite index on `(attribute_id, option_id)` already serves it. **No migration.**
   (ADR-0004 — no admin action ever runs DDL.)
2. **Is it a proven hot vector** (brand, model, geo)? Only then consider **promotion** to a dedicated
   column on `listing` — a developer migration (create column → backfill → index), and register it in
   the code-level promotion map the query builder reads. Start with **zero** promoted columns; promote
   only when the benchmark below fails.

## Index & performance
- Faceted filtering and counts run over indexed columns; geo uses PostGIS / `earthdistance`.
- **Always `EXPLAIN (ANALYZE, BUFFERS)`** the facet query, on real volume and without planner
  switches. A Seq Scan is **evidence, not a failure** (ADR-0026 d.3): record its row count and why the
  planner chose it. Investigate one on a *selective* leg (a selected facet's `EXISTS`, a rare text
  term) — on `search_listing_text` a common word matching ~10 % of rows is a correct seq scan.
- Budget: **p95 < 200 ms per scenario at 10 req/s arriving over 10k+ listings** (Constitution §7,
  ADR-0026 as amended). Proven on the box 2026-11-28 by the 10 req/s re-run (B1–B8 p95 46–97 ms; docs/infrastructure.md §*Search: latency
  benchmark*), never by CI or a laptop. **Latency is the verdict; plans explain it.**
- **Measure service time before tuning.** A load p95 far above the 1-client time is queueing (CPU or
  PHP-FPM), which no index fixes. On the 4-core box the CPU is the queue: more FPM children made it
  worse.
- **`auto_explain`'s duration is execution only.** Many `EXISTS` legs cost *planning* — 20 selections
  spend 1.4 s in `bind` (pdo_pgsql plans at bind) for 26 ms of work. Measure with
  `log_min_duration_statement = 0` and sum `parse`/`bind`/`execute`.

## Free-text fields (title/description only)
- Postgres **FTS** primary; **pg_trgm** (`word_similarity` / `<%`) as a typo-tolerant fallback that
  fires only when FTS returns too few rows. Combine ranks with Reciprocal Rank Fusion, never averaging.
- **Write and read paths must mangle text identically** (same stemmer, `unaccent` on both sides) or
  search silently returns nothing.
- The **tsquery sanitizer is security-critical**: strip every tsquery operator; append `:*` to the
  last token only. Test it directly.

## If Postgres genuinely can't keep the budget
Only then add a Meilisearch adapter behind the same `SearchPort` — one binding change, no domain
churn. That is the whole reason the port exists.
