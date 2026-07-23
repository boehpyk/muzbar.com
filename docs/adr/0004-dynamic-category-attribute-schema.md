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
