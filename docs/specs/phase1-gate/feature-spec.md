# Feature Spec: phase1-gate

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `phase1-gate` — the **Gate — Schema-Mutation PoC** of the roadmap's Phase 1 (PRD
  suggested validation #2), plus the **restore-from-backup rehearsal** the same gate line asks for.
  A **proof slice**: it adds tests and a runbook, not product behaviour.
- **Bounded context(s):** **`Catalog`** (the admin defines and archives — existing handlers and admin
  UI, unchanged) and **`Listing`** (the seller's edit page and the value layer — unchanged). **No
  production code in either.** **`Search` is not opened** (Phase 2): the facet count is a query owned
  by the gate test (plan D1).
- **Aggregates / invariants touched:** none changed. Exercised: `Attribute` (C-21, C-28),
  `AttributeOption` (C-17, C-18), `Listing` (L-9, L-12, L-13, L-17). No new value object, event or port.
- **Related PRD items:** *Suggested validations* #2 ("adding … 'Fretboard Wood' via the Admin
  interface successfully propagates … to the UI form dynamic rendering logic without corrupting
  existing database search records"), §*Risks — Schema Drift*, FR-1, FR-3 (facets — previewed only).
- **Governing ADRs:** [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (no runtime
  DDL; the generic `(attribute_id, option_id)` index; "the schema-mutation PoC is the Phase 1 gate"),
  [ADR-0002](../../adr/0002-search-postgres-behind-port.md) (facet counts are `GROUP BY` over indexed
  columns; the latency benchmark is Phase 2's), [ADR-0019](../../adr/0019-listing-context-cross-context-ports-leaf-only-under-one-lock-in-two-modes.md)
  (table names stay in their own context's Infrastructure — plan D1 says why a test query does not
  breach it), [ADR-0022](../../adr/0022-schema-drift-archive-reserved-names-dormant-values.md)
  (*"the `phase1-gate` facet query must speak 'active'"*).
- **Status:** Approved 2026-11-22 — Q1–Q6 as recommended
- **Date:** 2026-11-22

> **What this slice teaches, beyond the feature.**
>
> 1. **A gate is a test that could have failed.** ADR-0004 bet that an admin can add "Fretboard Wood"
>    with no migration, no DDL and no damage to existing rows. Every slice since has assumed it; this
>    one runs it end to end, through the same HTTP doors an admin and a seller use.
> 2. **"Active" is four clauses, and each needs a row that only it excludes.** A facet query that
>    forgets one still returns plausible numbers. The fixture therefore holds one listing per clause
>    (a draft, a withdrawn one, one outside the subtree, a stale option, an archived attribute, an
>    out-of-lineage attribute) — and the bite proof removes each clause in turn.
> 3. **Be honest about what an `EXPLAIN` on twelve rows proves.** It can prove that the generic index
>    *can* serve the query once the "active" joins are in (no clause made it unindexable). It cannot
>    prove the planner *will* choose it on real data, or that the query is fast. That is Phase 2's
>    latency gate, with 10 000 analysed rows — and this spec says so instead of implying more.
> 4. **An untested backup is a rumour** (docs/infrastructure.md). The rehearsal is manual, on the box,
>    and closes only on written evidence.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Schema-Mutation PoC** | The gate: an admin defines a new choice attribute through the admin UI; it appears on an existing draft's edit form with no deploy; a seller fills and publishes it; a facet count finds it; no DDL ran and no pre-existing listing row changed. | A migration-driven schema change (developer-only, deploy-time — ADR-0004 §3). |
| **Browsed category** | The category a (future) search page is scoped to: any node, leaf or not. | The listing's own (leaf) category. |
| **Facet count** (this slice) | For one browsed category and one **choice** attribute: `option → number of published listings in the browsed subtree holding it`, counting only what is **active**. | Phase 2's `SearchPort` facet result (filters, ranges, several facets at once). |
| **Active facet** | Four clauses together: listing `status = 'published'`; listing in the browsed **subtree**; attribute **active and in the browsed category's lineage** (its active effective schema, C-22); option **active** (`archived_at IS NULL`). | A bare `GROUP BY option_id` — which counts drafts, dormant values (L-17), stale values and archived attributes. |
| **Gate query** | The one SQL statement that computes the facet count, owned by the gate test (plan D1) — the artifact Phase 2's `Search` adapter starts from. | A production read model (there is none in this slice). |
| **Generic index** | `idx_listing_attribute_value_option` on `listing_attribute_value (attribute_id, option_id)` — ADR-0004 §2, created by `listing-attribute-values`. Serves every attribute, including ones defined after deploy. | A hot-column index (ADR-0004 §3 — none exist, and promotion moved to Phase 2). |
| **Restore rehearsal** | Dump prod, restore the dump into a **scratch** database on the same Postgres, prove it is complete, drop the scratch database. Manual, on the box. | A rollback (replacing prod's database — never done here). |

## User story

As **the owner**, I want **proof that an attribute an admin adds at runtime reaches the seller's form
and an "active-only" facet count — with no DDL, no migration and no change to existing listing rows,
over an index that can serve it — and proof that a production dump restores**, so that **I can enter
Phase 2 (search) on a data model and a backup I have actually seen work**.

## In scope

- **One functional gate test** (`SchemaMutationGateTest`) driving the real admin and seller pages in
  one `KernelBrowser`, against `muzbar_test` (DAMA).
- **The gate query**, as a documented constant in that test, and the fixture that makes each of its
  four "active" clauses observable.
- **An `EXPLAIN (FORMAT JSON)` assertion** on the gate query, with planner settings that make the
  attribute-led plan the one under test (plan D3).
- **Reuse** of `NoDdlTest`'s catalog snapshot (extracted to a test trait) for the no-DDL check.
- **The restore-rehearsal runbook** in `docs/infrastructure.md` §*Backups & recovery*, and its
  **manual** execution on the box by the owner, with the evidence recorded there.
- Docs: ADR-0004 amendment, roadmap, CLAUDE.md status, FORboehpyk.md.

## Non-goals (explicit — hold the line)

- **No `Search` context, no `SearchPort`, no `FacetedQuery`, no production facet read model** (plan
  D1). Phase 2 designs the port first (ADR-0002) and lifts the gate query into its adapter.
- **No new index, no migration, no change to `listing_attribute_value`** — the generic index exists
  (plan D2). Index tuning (e.g. `INCLUDE (listing_id)`, a `(status, category_id)` index on
  `listing_listing`) is measurement-driven: Phase 2's benchmark.
- **No latency claim.** No 10k-row seed, no timing, no `ANALYZE` — Phase 2's *Search Latency
  Benchmark* gate.
- **No hot-column promotion mechanism** (dropped from this item by the roadmap; Phase 2 latency gate).
- **No `is_facet` / `is_searchable` flags** (ADR-0004 amendment 2026-11-15: deferred to Phase 2).
  Every active choice attribute in the lineage is a candidate here.
- **Choice attributes only.** Number ranges, booleans and text are Phase 2 facets.
- **No public search page, no public-page assertion** (the public listing page's rendering of values
  is `PublicListingTest`'s).
- **API-key issuance is out** (plan Q4) — a separate Phase 1 roadmap item.
- **No scheduled backups, no off-box copy automation, no `make db.restore` target** (plan Q5, Q6).
  The daily-dump to-do in `docs/infrastructure.md` stays open.
- **No production code change of any kind.** If a test here fails on correct-looking code, that is a
  finding for `/verify`, not licence to add a feature.

## Acceptance criteria (the Definition of Done checklist)

Fixture, unless an AC says otherwise: `BuildsTheValuesTree` (Instruments > Guitars > Electric,
Instruments > Bass; Condition on Instruments, Brand on Guitars, Strings/Has case/Serial on Electric,
Bass strings on Bass). **Pre-existing rows** = every `listing_listing` and `listing_attribute_value`
row present before the define in AC-1. "Fretboard wood" = choice, code `fretboard-wood`, options
Rosewood, Maple, not required, defined on **Instruments**.

### A. The schema mutation, end to end (one scenario, one `KernelBrowser`)

- [ ] **AC-1 (admin defines through the UI)** — An admin (`ROLE_ADMIN`, granted before sign-in)
      `POST`s the define-choice form at `/admin/categories/{instruments}/attributes/new/choice` with
      a token scraped from the form's `GET`. Answer: **303** to the category's attribute list.
      Result: exactly **one** new `catalog_attribute` row (`code = 'fretboard-wood'`) and exactly
      **two** new `catalog_attribute_option` rows (Rosewood, Maple). No other `catalog_*` row changes.
- [ ] **AC-2 (no DDL, no migration)** — The columns and indexes of schema `public` (the
      `information_schema.columns` + `pg_indexes` snapshot `NoDdlTest` takes) are **identical**
      before AC-1 and after AC-4. The slice's diff contains **no file under `src/migrations/`**.
- [ ] **AC-3 (renders on the draft form, no deploy)** — Ann's pre-existing draft **D** on Electric,
      holding the five values of `allFiveElectricInputs()`: after AC-1 her `GET
      /sell/drafts/{D}/edit` answers 200 with **six** Details fields (five before AC-1 — asserted
      first); the new one is labelled "Fretboard wood", is a select offering `—`, `Rosewood`, `Maple`
      in that order, and is **empty**; the other five are pre-filled exactly as before AC-1.
- [ ] **AC-4 (a seller fills and publishes it)** — Ann submits that form (token from the page) with
      the five values unchanged, Fretboard wood = Rosewood and `action=publish`. Answer: **303**.
      Read back through a fresh connection: D's `status = 'published'`, `published_at` set; D has
      **six** value rows, the Fretboard one `option_id = Rosewood`.
- [ ] **AC-5 (existing rows untouched)** — Raw `SELECT *` snapshots, ordered by key: every
      pre-existing `listing_listing` row **other than D's** and every pre-existing
      `listing_attribute_value` row (D's five included) is **byte-identical** after AC-4. The
      fixture holds at least one pre-existing listing besides D (Bob's published Electric listing,
      five values) so this compares something; the test asserts the snapshot is non-empty.

### B. The facet count speaks "active"

The gate query, run with `(browsed category id, attribute id)`, returns `option label → count`,
ordered by label. Section B has its **own** fixture, independent of section A: the values tree,
Fretboard wood defined through `DefineAttributeHandler` (the admin UI path is section A's), a second
choice attribute **Finish** (Gloss, Matte) on Instruments, then these listings built **after** the
defines, through the aggregate (`ListingFactory` `withValues()` / `published()` / `withdrawn()`),
each holding Condition = Used and — on Electric — the Brand shown (both are required to publish):

| Listing | Category | Status | Fretboard wood | Brand | Finish |
|---|---|---|---|---|---|
| P1 | Electric | published | Rosewood | Fender | — |
| P1b | Electric | published | Rosewood | Gibson | — |
| P2 | Bass | published | Rosewood | — | — |
| Dr | Electric | **draft** | Rosewood | Fender | — |
| W | Electric | **withdrawn** | Rosewood | Fender | — |
| S | Electric | published | **Maple** | Fender | — |
| F | Electric | published | — | Fender | Gloss |

- [ ] **AC-6 (it finds the new attribute)** — Before any archive: browsing **Guitars** / Fretboard
      wood → `{Maple: 1, Rosewood: 2}`; browsing **Instruments** / Fretboard wood →
      `{Maple: 1, Rosewood: 3}`.
- [ ] **AC-7 (published only)** — Dr and W are never counted (with them, Rosewood would be 4 at
      Guitars). A separate AC so mutation (a) names it.
- [ ] **AC-8 (subtree only)** — P2 (Bass) counts at Instruments and **not** at Guitars (AC-6's
      2 vs 3).
- [ ] **AC-9 (archived option → no bucket)** — After Maple is archived through
      `ArchiveAttributeOptionHandler`: Guitars / Fretboard wood → `{Rosewood: 2}` — no Maple bucket,
      S counted nowhere for this attribute. S's value rows are byte-identical before and after the
      archive (a stale value stays stored, ADR-0022).
- [ ] **AC-10 (archived attribute → no facet)** — Instruments / Finish → `{Gloss: 1}`; after Finish
      is archived through `ArchiveAttributeHandler` → **no rows**. F's value rows are byte-identical.
- [ ] **AC-11 (lineage only)** — Guitars / Brand → `{Fender: 3, Gibson: 1}` (P1, S, F; P1b — Dr and W
      excluded by AC-7); **Instruments / Brand → no rows**: Brand is defined on Guitars, so it is not
      in Instruments' effective schema and is not a facet there, although published listings below
      hold it.
- [ ] **AC-12 (dormant-by-move needs no clause of its own — stated, not tested)** — If an attribute
      is in the browsed category's lineage and a listing is in its subtree, the attribute is in the
      listing's lineage, so no value counted can be dormant by a move. The gate query's docblock says
      so; mutation (b) covers the clause that makes it true.

### C. The index

- [ ] **AC-13 (the generic index can serve the active query)** — Inside the test's transaction,
      with `SET LOCAL enable_seqscan = off`, `enable_nestloop = off`, `enable_mergejoin = off`
      (plan D3), `EXPLAIN (FORMAT JSON)` of the gate query for Instruments / Fretboard wood contains
      **a plan node whose `Index Name` is `idx_listing_attribute_value_option`** and **no `Seq Scan`
      node on `listing_attribute_value`**. On failure the message prints the plan. The test's
      docblock states what this does **not** prove (planner choice on real data, latency — Phase 2).
- [ ] **AC-14 (the value layer's shape is unchanged)** — `ListingAttributeValuesSchemaTest`
      (listing-attribute-values AC-6, the table's only shape guard) passes **unmodified**.

### D. Restore-from-backup rehearsal (manual, on the box)

- [ ] **AC-15 (runbook)** — `docs/infrastructure.md` §*Backups & recovery* gains a *Restore
      rehearsal (runbook)* with copy-pasteable commands for the box (they must work **without** the
      Makefile and with `MUZBAR_IMAGE` set as the box expects): dump; create a scratch database
      `muzbar_restore_rehearsal`; `pg_restore --no-owner --no-privileges --exit-on-error`; the
      completeness checks; drop the scratch database. It never names prod's database as a restore
      target.
- [ ] **AC-16 (rehearsed, with evidence)** — The owner runs it on the box once. Closed only when a
      dated *Rehearsal log* entry under the runbook records: dump file name and size; `pg_restore`
      exit status **0**; the per-table exact row counts of prod and of the scratch database with
      **an empty diff** (or each difference explained by a write after the dump's timestamp);
      `doctrine:migrations:up-to-date` **exit 0** and `doctrine:schema:validate --skip-mapping`
      **exit 0** run in the `app` container with `DATABASE_URL` pointed at the scratch database (the
      restored schema is the one the deployed code expects); dump and restore durations; the scratch
      database dropped (`\l` no longer lists it). **Manual — no CI evidence substitutes for it.**

### E. Proof and docs

- [ ] **AC-17 (tests bite)** — each mutation fails a named test (recorded in the commit message):
      - (a) gate query without `l.status = 'published'` → AC-7
      - (b) gate query without the subtree join → AC-8
      - (c) gate query without `a.archived_at IS NULL` → AC-10
      - (d) gate query without `o.archived_at IS NULL` → AC-9
      - (e) gate query without the lineage predicate on `a.category_id` → AC-11
      - (f) gate query with `v.attribute_id::text = :attribute` (unindexable) → AC-13
      - (g) `DROP INDEX idx_listing_attribute_value_option` at the start of the EXPLAIN test (DAMA
        rolls it back) → AC-13
      - (h) `DoctrineListingRepository::writeValues()`'s `DELETE` without its `listing_id` predicate
        → AC-5
      - (i) an `ALTER TABLE catalog_attribute ADD COLUMN …` executed in `DefineAttributeHandler` →
        AC-2
      - (j) `DbalAttributeSchema::effectiveFor()` dropping the root of the lineage → AC-3
      - (k) the define-choice controller action not passing the option labels → AC-1 / AC-3
- [ ] **AC-18 (docs)** — ADR-0004 dated amendment (plan D7); roadmap: `phase1-gate` ticked, the
      *Gate — Schema-Mutation PoC* line marked **MET** with the date (only after AC-16), the Phase 2
      `Search` item gains the two carry-overs (lift the gate query; decide the cross-context read —
      plan Risk 2); CLAUDE.md status; FORboehpyk.md chapter.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| A gate AC fails on unchanged production code | `/verify` finding: the gate is **not met**; the fix is a production-code slice of its own (or an ADR change), not a weaker test. |
| The planner picks a different index under the AC-13 settings on a future PostgreSQL | AC-13 fails with the plan printed; re-read D3 — the assertion is about usability of the generic index, so adjust the settings only if the plan still shows `listing_attribute_value` served by an index; never accept a `Seq Scan` on it. |
| The facet query is lifted into Phase 2 without one of its four clauses | Phase 2 must carry AC-7…AC-11's fixture with it (Risk 2); until then, those ACs only guard the test's own copy. |
| `pg_restore` reports errors or exits non-zero | Rehearsal **failed**: record the output in the log, do not tick the gate, open a fix (dump flags, roles, extensions). Prod is untouched — the scratch database is the only target. |
| Row counts differ between prod and the scratch database | Explain each difference by a write after the dump (e.g. `prelaunch_signup.created_at`); an unexplained one fails the rehearsal. |
| The box lacks disk for a second copy | Stop; record free space; the rehearsal moves to a machine with the dump copied off-box (plan Q5). Never restore over prod to save space. |
| Scratch database left behind after a failure | The runbook's last step (`DROP DATABASE IF EXISTS muzbar_restore_rehearsal`) is safe to run on its own. |
