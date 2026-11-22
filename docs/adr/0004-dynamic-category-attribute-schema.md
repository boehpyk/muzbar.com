# ADR-0004: Hybrid dynamic category/attribute schema

- **Status:** Accepted
- **Date:** 2026-07-23

## Context

Admins must create/edit/delete instrument categories and their attribute schemas *at runtime* (PRD
FR-1), and those attributes drive both the morphing listing form and the faceted search. The PRD
flags the core risk as **schema drift**: changing metadata on live category trees without corrupting
downstream search. Two classic extremes both hurt:

- **Pure EAV** (everything in an attribute-value table): infinitely flexible, but faceted filtering
  and counts become many self-joins — hard to keep under the 200 ms budget, and easy to write wrong.
- **Pure JSONB** (`attributes jsonb` per listing): flexible and index-able with GIN, but exact
  faceted range/equality with counts is awkward and the schema lives nowhere queryable.

## Decision

A **hybrid, metadata-driven model**. **Hard invariant: no admin action ever runs DDL.** Everything an
admin does is INSERT/UPDATE on data rows; the table schema only changes through reviewed, deploy-time
migrations written by a developer.

1. **Schema layer (the ubiquitous language, in tables):** `category`, `attribute`
   (`category_id, code, label, data_type, is_facet, is_searchable`), `attribute_option` (predefined
   dropdown values). This is the `Catalog` bounded context; **admins edit *this*, at runtime**.
   `is_facet` is an admin-controlled flag ("show this as a filter?") — it is metadata, it does **not**
   alter any table.
2. **Value layer:** `listing_attribute_value` (`listing_id, attribute_id, option_id | value_int |
   value_text`), typed columns rather than one stringly-typed blob. **Generic** composite indexes on
   `(attribute_id, option_id)` etc. serve faceted filtering and `GROUP BY` counts for *every*
   attribute — including newly added ones — so **adding an attribute needs no migration**.
3. **Hot-path promotion (developer-only, deploy-time, optional):** a *fixed, known* set of
   high-frequency vectors (brand, model, geo — PRD §9) *may* be promoted to dedicated columns on
   `listing` with their own indexes. This is a **developer decision applied via a migration** (create
   column → backfill from the value layer → add index → deploy), **never** a runtime admin toggle. The
   query builder learns which attributes are promoted from a **code-level mapping tied to the
   migrations**, not from a mutable DB flag. **Start with zero promoted columns**; promote only if the
   ADR-0002 benchmark proves the generic value-layer index too slow (measurement-driven, per ADR-0002).

Schema changes are **additive migrations**, never in-place mutation of live data. Deleting an
attribute **soft-deletes** it and hides it from the form/facets; historical values are retained. A
**schema-mutation PoC is the Phase 1 gate** (PRD suggested-validation #2): adding e.g. "Fretboard
Wood" via the admin UI must propagate to the dynamic form and search **with no migration and no
corruption of existing rows** — which is the proof that the no-runtime-DDL invariant holds.

## Alternatives

- **Pure EAV:** rejected — facet performance and query correctness risk.
- **Pure JSONB:** rejected as the primary model — poor fit for counted, typed facets; kept as an
  option for genuinely free-form, non-faceted extra fields only.
- **A table-per-category (dynamic DDL):** rejected — runtime `ALTER TABLE`/`CREATE TABLE` from admin
  actions is the schema-drift nightmare the PRD warns about.

## Consequences

- **Easy:** admins get runtime flexibility with **zero migrations**; the schema is itself queryable
  data; adding an attribute is an ordinary data write.
- **Hard / watched:** facet-count queries over the value layer are the perf risk that ADR-0002's
  benchmark exists to catch — and the reason promotion exists as an escape hatch. **Only if/when** a
  hot column is promoted do we get a second write path (promoted column + value row) to keep
  consistent — enforced in the `Listing` aggregate, not scattered across services. Until then there is
  a single write path (the value layer), which is simpler and the default.
- The `Catalog` model (Category/Attribute as an aggregate with invariants — e.g. an option can't be
  deleted while listings reference it) is a rich DDD exercise, which suits the learning goal.

## Amendment, 2026-11-15 — hard delete until values exist; facet flags deferred

Recorded by the `catalog-attribute-schema` slice
([ADR-0017](./0017-attribute-schema-attributes-and-options-as-separate-aggregates.md)), on the
owner's answers Q4 and Q8. Two points only; **the `attribute` + `attribute_option` table sketch
stands** (as `catalog_attribute` / `catalog_attribute_option`, ADR-0007 naming).

1. **Attributes and options are hard-deleted for now.** The soft-delete clause above protects
   historical *values*, and no `listing_attribute_value` exists yet. An `archived_at` column with no
   reader would be speculative, and an archived code would still block C-11 (code unique within the
   category). Deleting an attribute deletes its options in the same transaction (C-20).
   **This clause expires with the first listing value:** the slice that introduces
   `listing_attribute_value` adds soft delete and the "in use by listings" guards to
   `DeleteAttributeHandler` and `RemoveAttributeOptionHandler`.
2. **`is_facet` / `is_searchable` are deferred to Phase 2.** Nothing reads them yet; both are
   additive columns when search arrives.

## Amendment, 2026-11-18 — amendment 1's expiry discharged by refusal; the value layer gains `value_bool`

Recorded by the `listing-attribute-values` slice
([ADR-0020](./0020-attribute-values-parts-of-listing-validated-against-catalog-schema-guarded-by-refusal.md), plan D6, D9).

1. **The 2026-11-15 amendment's expiry clause is discharged by refusal, not by soft delete.** The
   first listing value exists, and with it the "in use by listings" guards: **C-25** (an attribute in
   use cannot be deleted) and **C-26** (an option in use cannot be removed) refuse instead of
   archiving. Soft delete (`archived_at`, hidden from forms) moves to `listing-schema-drift`. Hard
   delete of attributes and options that **no** listing uses continues unchanged.
2. **The value layer is `listing_attribute_value (listing_id, attribute_id, option_id, value_int,
   value_bool, value_text)`.** `value_bool` is added to decision 2's sketch — a boolean is not a 0/1
   integer. A `CHECK (num_nonnulls(…) = 1)` keeps exactly one typed column set per row.
3. **Only `(attribute_id, option_id)` is indexed now.** Number-range, boolean and text indexes are
   one migration each when a Phase 2 query needs them. The no-runtime-DDL invariant is unchanged:
   defining an attribute writes rows, never a column.

## Amendment, 2026-11-21 — the soft-delete clause is implemented, as *archive*; hard delete survives for unused rows

Recorded by the `listing-schema-drift` slice
([ADR-0022](./0022-schema-drift-archive-reserved-names-dormant-values.md), plan Q1, D1).

1. **The decision's soft-delete clause is implemented — as a separate act called *archive*.**
   `catalog_attribute` and `catalog_attribute_option` gain `archived_at timestamptz(0) NULL`. An
   archived attribute is hidden from the seller's form, the public page and (Phase 2) the facets;
   every value that names it is retained and wakes on **restore**. An archived option is hidden from
   new choices; values on it are retained and shown as stored.
2. **Hard delete survives, for rows no listing uses** (C-25/C-26 unchanged; their refusal now points
   at Archive). "Deleting soft-deletes" therefore reads as *two buttons*: Delete for unused rows,
   Archive for any row.
3. **The value layer is untouched** — no column, index or row of `listing_attribute_value` changes.
   The no-runtime-DDL invariant is unchanged: archiving is an `UPDATE` of one row.

## Amendment, 2026-11-22 — the Schema-Mutation PoC ran; zero promoted columns stands

Recorded by the `phase1-gate` slice (plan D7). No decision changes; this records that the proof
this ADR named as the Phase 1 gate was run, and what it does and does not show.

1. **The bet held end to end, through the HTTP doors.** `SchemaMutationGateTest` section A: an admin
   defines a choice attribute "Fretboard wood" through `/admin/…/attributes/new/choice`; a seller's
   **pre-existing** draft shows it on its edit form with no deploy; she fills it and publishes. The
   `information_schema.columns` + `pg_indexes` snapshot of `public` is identical before and after
   (the `NoDdlTest` snapshot, now the `SnapshotsTheSchemaCatalogs` trait), the slice adds no
   migration, and every pre-existing `listing_listing` and `listing_attribute_value` row is
   byte-identical (raw `SELECT *`), except the draft's own listing row that the publish changes.
2. **A facet count over the generic table must speak "active" — four clauses** (ADR-0022): listing
   `status = 'published'`, listing in the browsed subtree, attribute active **and** in the browsed
   category's lineage (C-22), option active. The gate query (`SchemaMutationGateTest::FACET_COUNT`)
   carries them, and its fixture holds one row per clause that only that clause excludes; removing
   any one of them turns its test red. Dormant-by-move needs no fifth clause: subtree ∩ lineage
   cannot hold one.
3. **The `EXPLAIN` proves usability, not choice.** Under `SET LOCAL enable_seqscan/nestloop/mergejoin
   = off`, the gate query's plan contains an index **lookup** on `idx_listing_attribute_value_option`
   (`Index Cond` on `attribute_id`; a full index scan was not enough, so the test requires the
   lookup) and no `Seq Scan` on `listing_attribute_value`. That proves no "active" clause made the
   value leg unindexable. It does **not** prove PostgreSQL picks that plan on real, `ANALYZE`d data,
   or any latency: that is Phase 2's *Search Latency Benchmark* (10 000 rows, no planner switches).
4. **Zero promoted columns stands.** Nothing measured says one is needed; promotion lives with the
   Phase 2 benchmark.
5. **Carried to Phase 2's `Search` item:** lift the gate query **and its AC-6…AC-11 fixture** into
   the `SearchPort` adapter (the fixture is what proves the four clauses); decide how `Search` reads
   Catalog's and Listing's tables — the single joined statement breaks ADR-0019's "table names only
   in their own context's Infrastructure" once it moves into `src/`.
