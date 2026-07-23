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
- **Always `EXPLAIN (ANALYZE, BUFFERS)`** the facet query. Every filter must hit an index — a Seq Scan
  on `listing_attribute_value` is the failure signal.
- Budget: **< 200 ms @ 50 concurrent over 10k+ rows** (Constitution §7). This is the Phase 2 gate.

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
