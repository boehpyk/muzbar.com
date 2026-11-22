# Technical Plan: phase1-gate

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order — which, for a proof slice, is mostly a list of layers left alone.

## Decisions

### Open questions for the owner (the plan is written to the recommendation)

| # | Question | Recommendation |
|---|---|---|
| Q1 | **Where does the facet-count query live?** (a) a query owned by the gate test; (b) a minimal real read model (e.g. `Infrastructure/Listing/…/DbalFacetCounts`, or a nascent `Search` adapter) that Phase 2 builds on. | **(a), the test** (D1). A read model with no production caller is dead code by the house rule, and it would pre-decide Phase 2's two real questions — the `SearchPort` shape (ADR-0002 says design the port *first*) and how Search reads two contexts' tables (Risk 2). The gate proves the **data model and the index** answer the question; Phase 2 lifts the query **and its fixture** into the adapter. |
| Q2 | **Define "Fretboard wood" through the admin UI (HTTP) or the admin handler?** The roadmap item says "handlers", the gate line and PRD #2 say "via the admin UI". | **The admin UI, for section A** (one `POST`, the same handler underneath). It is PRD #2 verbatim and costs one request. Section B (the facet fixture) uses the handlers — the UI path is already proven once, and B is about the query. `SellTest`'s AC-19 already previews the handler path; it stays as is. |
| Q3 | **How is the `EXPLAIN` asserted on a dozen rows?** | **Planner switches + "the generic index appears, no seq scan on the value table"** (D3). The alternatives: `enable_seqscan = off` alone is non-deterministic (a nested loop over the PK is equally legal); seeding thousands of rows + `ANALYZE` is Phase 2's benchmark, done badly. The test states what it does not prove. |
| Q4 | **API-key issuance (the other unchecked Phase 1 item): in or out?** | **Out of this slice.** It has no consumer until Phase 3's endpoints, so "model only" now is the same scaffolding argument that moved hot-column promotion out of this item. **Owner call:** move it to Phase 3 (beside the endpoints) so Phase 1 can close on this gate, or keep it as a Phase 1 slice that must land before Phase 2 starts. Recommendation: **move it to Phase 3**. |
| Q5 | **Rehearsal scope: on the box only, or also off-box?** | **On the box, into a scratch database** (the roadmap's wording; proves the dump is complete and restorable by the same Postgres major). Copying that dump to your laptop is a good *habit* but proves a different thing (box loss), which belongs with the still-open daily-dump/off-box to-do. Say if you want both now. |
| Q6 | **A `make` target for the rehearsal?** | **No — a runbook.** It runs once now and "after any schema-mutation milestone"; the box may not even hold the Makefile (prod ships compose + `.env` only). Add a target when it becomes scheduled. |

### Design decisions

| # | Decision | Recommendation | Alternatives considered |
|---|---|---|---|
| **D1** | **The gate query is a constant in the gate test, one statement, joining both contexts' tables** | `SchemaMutationGateTest::FACET_COUNT` (SQL below), with a docblock: what each clause excludes, the AC-12 argument (dormant-by-move is impossible inside subtree ∩ lineage), and *"Phase 2: lift this and AC-6…AC-11's fixture into the `Search` adapter"*. **ADR-0019 is not breached:** its rule ("a table name appears only in its own context's Infrastructure") governs `src/`, so a schema change stays in one directory; tests already read every table (`SellTest::valueRows()`, `NoDdlTest`). The single joined statement is deliberate: it is the shape whose `EXPLAIN` matters (the planner sees the "active" joins), and the hardest one for the index. | **Compose it the ADR-0019 way** (Catalog read model → subtree ids, active option ids; Listing query with `IN (…)`): honest for production, but it decides Phase 2's crossing for it, and the `EXPLAIN` would then measure a different statement. **A production read model:** Q1. |
| **D2** | **No new index, no migration** | `idx_listing_attribute_value_option (attribute_id, option_id)` exists (`Version20261118120000`, whose docblock already names "the Phase 1 gate's facet count"). The gate query's value-table leg is `v.attribute_id = :attribute` with `v.option_id` joined → the index's leading column plus the second. `listing_listing` has `idx_listing_listing_category` and its PK; nothing indexes `status` — **not added**: no measurement says it is needed (Phase 2). `ListingAttributeValuesSchemaTest` (the unmapped table's only shape guard, `schema_filter` hides it from every Doctrine diff) therefore stays **untouched** — AC-14. | **`INCLUDE (listing_id)`** for an index-only scan, **`(status, category_id)`** on `listing_listing`, **a partial index `WHERE option_id IS NOT NULL`:** each plausible, each a guess without the 10k-row benchmark; each would also be a hand-written migration **plus** an AC-6 shape-test update for the value table. |
| **D3** | **`EXPLAIN` under three planner switches; assert usability, not choice** | In the test, after the fixture: `SET LOCAL enable_seqscan = off; SET LOCAL enable_nestloop = off; SET LOCAL enable_mergejoin = off;` then `EXPLAIN (FORMAT JSON) <gate query>` with the same parameters. Walk the JSON `Plans` tree; assert (1) some node has `"Index Name": "idx_listing_attribute_value_option"` (an `Index Scan`, `Index Only Scan` or `Bitmap Index Scan` all qualify); (2) no node is `"Node Type": "Seq Scan"` with `"Relation Name": "listing_attribute_value"`. Print the plan on failure. **Why these switches:** a disabled node type is still used when nothing else can answer (PostgreSQL adds a cost penalty, it never fails the plan), so `enable_seqscan = off` turns "is any index usable?" into an observable — if a clause made the value leg unindexable (mutation (f)), the seq scan appears anyway. Disabling nested-loop and merge joins removes the per-listing PK probe (`listing_id, attribute_id`), which is a *legitimate* plan for a narrow subtree but answers a different question; what is left is the attribute-led hash-join leg, the one ADR-0004 §2 promised the generic index would serve. **What it does not prove (said in the docblock):** which plan PostgreSQL picks on real, `ANALYZE`d data, or any timing — the Phase 2 latency gate (10k rows, 50 concurrent, `EXPLAIN` on every facet). `SET LOCAL` dies with DAMA's rollback; the `EXPLAIN` runs in its own test method so no other query in the test runs under the switches. If PDO refuses to bind parameters into `EXPLAIN`, inline the test-controlled UUIDs as literals (they are generated by the test, never input). | **`enable_seqscan = off` only:** nested loop over the PK may win on cost → flaky. **Seed + `ANALYZE`:** a slow test pretending to be a benchmark. **Assert "uses an index" (any):** passes with the PK probe even when the generic index is gone (mutation (g) would survive). **`EXPLAIN ANALYZE` timings:** meaningless at this size. |
| **D4** | **"Existing rows untouched" = raw snapshots, not counts** | Snapshot `SELECT * FROM listing_listing ORDER BY id` and `SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id` **before AC-1**; compare after AC-4 (excluding only D's `listing_listing` row, which AC-4 legitimately changes; D's five pre-existing value rows are compared too). Read through DBAL after the requests (no identity map involved). Same technique as `SchemaDriftTest::rowsOfD()`. Assert the snapshot holds more than D, or it compares nothing. | **Row counts:** blind to an `UPDATE`. **Checksums:** the same thing, harder to read on failure. |
| **D5** | **No-DDL: reuse `NoDdlTest`'s catalog snapshot through a trait** | Extract `NoDdlTest::catalogs()` (the `information_schema.columns` + `pg_indexes` query pair) into `tests/Support/SnapshotsTheSchemaCatalogs` and use it in both tests. This covers what `NoDdlTest` does not: the **HTTP** path (controller + form + handler) and the seller save. A pure move — `NoDdlTest` must stay green with no other change. | **Copy the two queries:** two copies drift. **Rely on `NoDdlTest` alone:** it drives handlers, not the controller. |
| **D6** | **The rehearsal restores into a scratch database on prod's own Postgres and checks completeness three ways** | Runbook (docs/infrastructure.md §*Backups & recovery → Restore rehearsal*), using raw `docker compose` (the box may lack the Makefile; `MUZBAR_IMAGE` is in the box `.env` since 2026-11-14): (1) dump with the **exact** `make db.dump` command line into `backups/` (`-Fc --no-owner --no-privileges`); (2) `createdb muzbar_restore_rehearsal`; (3) `pg_restore --no-owner --no-privileges --exit-on-error -d muzbar_restore_rehearsal < dump` → exit 0; (4) exact per-table row counts in both databases via one query (`query_to_xml(format('select count(*) from %I', table_name)…)` over `information_schema.tables`), `diff` → empty or explained; (5) in the `app` container, `docker compose exec -e DATABASE_URL=<scratch> app php bin/console doctrine:migrations:up-to-date` and `doctrine:schema:validate --skip-mapping` → both exit 0 (the restored schema is the one the running code expects; the count diff covers the unmapped tables `schema_filter` hides); (6) `DROP DATABASE muzbar_restore_rehearsal`. Record timings with `time`. **Evidence:** a dated *Rehearsal log* line under the runbook (AC-16). | **Restore into a throwaway container** (`postgres:16-alpine` with its own volume): proves the same, costs a second container and a volume to clean up. **Restore over prod:** never. **A `pg_dump` of the scratch DB diffed against the original:** byte noise (ordering, OIDs) without more signal than (4)+(5). |
| **D7** | **ADR: one dated amendment to ADR-0004, no new ADR** | Nothing new is decided about the architecture: the index (ADR-0004 §2), the active semantics (ADR-0022) and the benchmark (ADR-0002) are all already decided. The amendment records that **the PoC gate is met** and how (the test, the query's four clauses, what D3's `EXPLAIN` does and does not show), that promotion stays at zero columns pending Phase 2's measurement, and the two Phase 2 carry-overs (Risk 2). | **A new ADR "facet query semantics":** ADR-0022's consequences already state them. **No ADR text at all:** ADR-0004 names this gate as its own proof; it should say the proof ran. |

## The gate query

```sql
WITH RECURSIVE
  subtree (id) AS (
    SELECT id FROM catalog_category WHERE id = :browsed
    UNION ALL
    SELECT c.id FROM catalog_category c JOIN subtree s ON c.parent_id = s.id
  ),
  lineage (id, parent_id) AS (
    SELECT id, parent_id FROM catalog_category WHERE id = :browsed
    UNION ALL
    SELECT p.id, p.parent_id FROM catalog_category p JOIN lineage l ON p.id = l.parent_id
  )
SELECT o.label, count(*) AS listings
FROM listing_attribute_value v
JOIN listing_listing l          ON l.id = v.listing_id
                               AND l.status = 'published'                       -- (a) AC-7
JOIN subtree s                  ON s.id = l.category_id                         -- (b) AC-8
JOIN catalog_attribute a        ON a.id = v.attribute_id
                               AND a.archived_at IS NULL                        -- (c) AC-10
                               AND a.category_id IN (SELECT id FROM lineage)    -- (e) AC-11
JOIN catalog_attribute_option o ON o.id = v.option_id
                               AND o.archived_at IS NULL                        -- (d) AC-9
WHERE v.attribute_id = :attribute                                               -- (f) AC-13
GROUP BY o.label
ORDER BY o.label
```

The implementer may reshape it (e.g. `EXISTS` for the lineage) as long as every lettered clause stays
separately removable for the bite proof. The test helper returns `array<string, int>` (label → count).
`count(*)` counts listings, because the PK `(listing_id, attribute_id)` allows one value per listing
per attribute.

## Domain layer (pure PHP)

**No change.** No aggregate, invariant, value object, domain event or port is added or modified.
Exercised as they are: `Attribute`/`AttributeOption` (define, C-17/C-18/C-21; archive, C-28),
`Listing` (`revise()` L-9 reading the new attribute from `schemaOf()`; `publish()` L-13/L-14).

## Application layer

**No change.** Driven: `DefineAttributeHandler` (via the controller in A; directly in B),
`ArchiveAttributeOptionHandler`, `ArchiveAttributeHandler` (B), `ReviseListingHandler` with
`$publish = true` (via `SellController` in A).

## Infrastructure layer

**No change** to `src/`: no mapping, adapter, controller, template, route, DI or migration.

## Tests (the slice's diff)

- **`tests/Support/SnapshotsTheSchemaCatalogs.php`** — new trait (D5); `NoDdlTest` uses it.
- **`tests/Functional/SchemaMutationGateTest.php`** — new; `WebTestCase`, one `KernelBrowser`,
  `disableReboot()`, `ClearsRateLimiters` (it drives `/login`), `BuildsTheValuesTree`,
  `RegistersAUserWithKnownCredentials`, `SignsInThroughTheLoginForm`, `SnapshotsTheSchemaCatalogs`.
  Lives at the root of `tests/Functional/` (beside `HealthTest`) because it spans Catalog and Listing.
  - `testTheSchemaMutationReachesTheDraftFormWithNoDdlAndNoExistingRowChanged()` — A (AC-1…AC-5) as
    one scenario. Admin (`admin: true`, registered **before** sign-in — a role change logs a signed-in
    user out) and Ann; sign in as the admin, define through the form, then sign in as Ann through the
    login form in the same client (if re-login while signed in proves awkward, sign out through the
    `POST` logout form first). Bob's published Electric listing (five values, `ListingFactory`
    `published()`) and Ann's draft D are the pre-existing rows. Read back through a **fresh** DBAL
    read after each request (CLAUDE.md: a repository fetched in `setUp()` serves the identity map).
  - Section B — one method per AC-6…AC-11 (or a data provider over `(browsed, attribute, expected)`
    for AC-6/AC-8/AC-11, plus separate methods for the archive ACs), sharing `buildTheFacetFixture()`.
  - `testTheGenericIndexCanServeTheActiveFacetQuery()` — AC-13 (D3), last in the file by convention,
    its own method so `SET LOCAL` touches nothing else.
- **`SellTest`'s AC-19** (the gate, previewed through the handler) stays: it is that slice's AC.
- **`ListingAttributeValuesSchemaTest`** — not modified (AC-14).

## Interface boundary & input contract

No production surface changes. The surfaces the gate drives, as they are:

| Surface | Used with | Expected |
|---|---|---|
| `POST /admin/categories/{id}/attributes/new/choice` | the form's own fields + scraped `_token`, `ROLE_ADMIN` | 303; one attribute, its options |
| `GET /sell/drafts/{id}/edit` | Ann, owner of D | 200; six Details fields |
| `POST /sell/drafts/{id}/edit` | `listing_form[…]` + `_token`, `action=publish` | 303; D published, six value rows |
| `ArchiveAttributeOptionHandler` / `ArchiveAttributeHandler` | ids from the fixture | no exception; no listing row written |
| Gate query | `:browsed` (any category id), `:attribute` (any attribute id) | `label → count`; empty when the attribute is not an active facet of the browsed category |

## Data & migrations

**None.** No table, column, index or backfill (D2). Deploy: nothing to deploy for the app — the slice
ships tests and docs. The rehearsal touches prod only by **reading** it (`pg_dump`) and by creating
and dropping `muzbar_restore_rehearsal` on the same server.

## Test plan

- **Domain unit:** none new (no Domain change).
- **Functional / integration (DAMA):** `SchemaMutationGateTest` (AC-1…AC-11, AC-13); `NoDdlTest`
  after the trait extraction; `ListingAttributeValuesSchemaTest` unchanged (AC-14).
- **Bite proof (AC-17):** apply (a)…(k) one at a time, run the named test, record red/green in the
  commit message, revert. (a)–(f) mutate the gate query itself: that is the point — they prove the
  fixture **discriminates every clause**, which is what makes the query safe to lift into Phase 2.
- **CI parity:** run once with `php -d zend.assertions=-1` (no work inside `assert()`; CLAUDE.md).
- **Twice in a row** (`make test` ×2) — the test signs in, so the rate-limiter pool must be cleared.
- **Manual:** the restore rehearsal (AC-16), by the owner, on the box.

## Risks / open questions

1. **Q1–Q6 above.** Q1 (test-owned query) and Q4 (API keys → Phase 3) are the owner's real calls; the
   rest have safe defaults.
2. **Phase 2 carry-overs (written into the roadmap's `Search` item, T7):**
   - **Lift the gate query and its fixture** into the Postgres `SearchPort` adapter. Until then
     AC-7…AC-11 guard only the test's own copy; a hand-rewritten Phase 2 query without the fixture
     would lose the proof.
   - **Decide how `Search` reads Listing's and Catalog's tables.** The single joined statement breaks
     ADR-0019's "table names only in their own context's Infrastructure" once it moves into `src/`.
     Options for then: `Search` as a read-only projection context explicitly allowed to read both
     (an ADR-0019 amendment), or composition through ports with id lists. Not decided here.
   - **The `EXPLAIN` that decides index tuning** (and hot-column promotion) is taken on ANALYZEd 10k
     rows, without planner switches.
3. **The planner switches make AC-13 a statement about usability, not choice.** A reader skimming
   the test could take it as "facets are fast". The test docblock and the ADR-0004 amendment both say
   otherwise, in those words.
4. **Parameter binding in `EXPLAIN`.** PostgreSQL accepts bound parameters in an `EXPLAIN` sent
   through the extended protocol, but if pdo_pgsql/DBAL balks, inline the test's own UUIDs (D3) —
   never the pattern for input from outside.
5. **Rehearsal disk space.** A second copy of the database on the same volume. The prod database is
   small (coming-soon stub, a few categories), but check `df` first (failure contract).
6. **Observed, not fixed:** `docs/cicd.md` *Rollback* says "restore from the pre-deploy
   `make db.dump`", but `deploy.yml` takes no dump. This rehearsal proves a dump restores; it does not
   make one exist before a deploy. Recorded in the runbook as a note; automating it belongs to the
   daily-dump to-do.
7. **ADR work:** ADR-0004 amendment only (D7). No new ADR.
