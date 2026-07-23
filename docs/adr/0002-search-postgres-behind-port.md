# ADR-0002: Postgres-first faceted search behind a `SearchPort`

- **Status:** Accepted
- **Date:** 2026-07-23
- **Supersedes the PRD's Meilisearch/Elasticsearch assumption** (PRD §9).

## Context

The PRD assumes Meilisearch or Elasticsearch. But muzbar's search is **faceted over structured
attributes** — brand, model, string count, hand orientation, geo — not fuzzy full-text over prose.
That is a *relational* problem: indexed `WHERE`/`GROUP BY` over typed columns, not a document search
engine's job. The performance budget (< 200 ms @ 50 concurrent over 10k+ items) is comfortable for
correctly indexed PostgreSQL.

Adding Meilisearch to a single VDS costs: another container, a Postgres→index **sync path**, and the
"write and read paths drift → search silently returns nothing" failure class the owner has hit before
(documented in the wiki's postgres-search synthesis). The owner's own recorded verdict for a harder
(multilingual prose) problem was *"FTS primary, pg_trgm fallback, hexagonal port keeps a swap one
binding away."*

## Decision

Implement search **on PostgreSQL**, reached exclusively through a Domain-defined **`SearchPort`**:

- **Faceted filtering:** composite B-tree indexes on the high-frequency vectors (brand, model, string
  count, orientation) and GiST/`earthdistance`/PostGIS for geo. Hot, admin-promoted attributes become
  real (or generated) columns; the long tail is queried via the attribute-value join (see ADR-0004).
- **Facet counts:** `GROUP BY` over the same indexed columns; revisit with partial indexes or a
  denormalized read table only if the benchmark demands it.
- **Free-text (title/description only):** Postgres FTS as primary, `pg_trgm` (`word_similarity`) as a
  typo-tolerant fallback that fires only when FTS returns too few rows. Combine ranks with Reciprocal
  Rank Fusion if needed — never by averaging.
- **Text hygiene:** identical mangling on write and read (`unaccent` both sides); the tsquery
  sanitizer strips every operator and appends `:*` to the last token only.

Meilisearch remains a **future adapter** behind the same port — added only when a real fuzzy-text
need or a proven facet-count bottleneck appears.

## Alternatives

- **Meilisearch from day 1 (PRD default):** rejected as premature; adds a container and a sync/consistency
  burden for a workload Postgres handles. The owner initially chose this, then reversed after weighing
  the trade-off.
- **pgvector semantic search:** wrong tool — returns the *wrong* author/model for exact lookups, and
  isn't installed on stock `postgres:16-alpine`.

## Consequences

- **Easy:** one datastore, no sync path, no "silently empty" failure mode, fewer moving parts on the box.
- **Hard / watched:** facet-count queries over the attribute-value join are the performance risk; the
  Phase 2 gate is an **explicit latency benchmark with `EXPLAIN`** to prove every facet hits an index.
  If it fails on real data, we revisit — cheaply, because of the port.
- The `SearchPort` interface (and a `FacetedQuery` value object as its input) must be designed before
  the read model — it is the boundary that keeps this decision reversible.
