# Task List: phase1-gate

> Ordered, small tasks. Each should be reviewable in < 5 minutes and is one commit on
> `feature/phase1-gate`. Run `make check` before each commit. Check off as you go.

**Status:** Approved 2026-11-22 — Q1–Q6 as recommended.

**Rules for the whole slice:**
- **No change under `src/src/`, `src/config/` or `src/migrations/`.** The slice is tests, docs and a
  runbook. If a gate AC fails on production code, stop: that is a `/verify` finding and its own fix.
- The gate query lives in the test (plan D1); every lettered clause stays separately removable.
- Read back through DBAL **after** each request; never through a repository fetched before it.
- No work inside `assert()` (CI runs `zend.assertions=-1`); use `if (…) { self::fail(…); }`.
- `ListingAttributeValuesSchemaTest` is not modified (AC-14).

## Domain / Application / Infrastructure

- (none — plan §Domain, §Application, §Infrastructure: no change)

## Tests (qa)

- [x] **T1:** Extract `NoDdlTest::catalogs()` into `tests/Support/SnapshotsTheSchemaCatalogs`;
      `NoDdlTest` uses the trait. Pure move — `NoDdlTest` green, nothing else changed (plan D5).
- [x] **T2:** `tests/Functional/SchemaMutationGateTest.php`, section A: fixture (values tree; admin
      with `admin: true` registered before sign-in; Ann; Bob's published Electric listing with five
      values; Ann's draft D with five values), the two snapshots (D4, D5), then the scenario
      AC-1 → AC-3 → AC-4, then AC-5 and AC-2. One method, one `KernelBrowser`, `disableReboot()`,
      `ClearsRateLimiters`.
- [x] **T3:** Section B: `FACET_COUNT` constant with its docblock (four clauses, the AC-12
      subtree ∩ lineage argument, "Phase 2 lifts this and its fixture"), `facetCount(browsed,
      attribute): array<string,int>`, `buildTheFacetFixture()` (spec §B table, through the handlers
      and `ListingFactory`), and AC-6 … AC-11. Exact expected maps, not "contains".
- [x] **T4:** AC-13: `testTheGenericIndexCanServeTheActiveFacetQuery()` — the three `SET LOCAL`
      switches, `EXPLAIN (FORMAT JSON)`, a recursive walk of `Plans`, the two assertions, the plan in
      the failure message, and the docblock saying what it does **not** prove (plan D3, Risk 3).
- [ ] **T5:** Bite proof (AC-17): mutations (a)…(k) one at a time, each turning its named test red;
      revert each. Record the table in the commit message. Then `make test` twice in a row and once
      with `php -d zend.assertions=-1 vendor/bin/phpunit` (CI parity).

## Ops (runbook + manual)

- [ ] **T6:** `docs/infrastructure.md` §*Backups & recovery* → *Restore rehearsal (runbook)* (plan
      D6): box-ready `docker compose` commands for dump → `createdb` → `pg_restore --exit-on-error`
      → per-table exact counts on both databases + `diff` → `doctrine:migrations:up-to-date` and
      `doctrine:schema:validate --skip-mapping` against the scratch DB → `DROP DATABASE`; the `df`
      pre-check; an empty *Rehearsal log*; the Risk 6 note (no pre-deploy dump exists today).
      AC-15.
- [ ] **T7 (MANUAL — owner, on the box):** run the runbook once; paste the evidence into the
      *Rehearsal log* (date, dump name and size, `pg_restore` exit 0, count diff, both console exit
      codes, durations, scratch DB dropped). AC-16. **The gate is not met until this is done.**

## Docs

- [ ] **T8:** ADR-0004 dated amendment (plan D7): the PoC gate is met — the test, the four "active"
      clauses, what the `EXPLAIN` shows and does not; zero promoted columns stands; the Phase 2
      carry-overs. Roadmap: tick `phase1-gate`; mark *Gate — Schema-Mutation PoC* **MET** with the
      date (after T7); add plan Risk 2's two carry-overs to Phase 2's `Search` item; move API-key
      issuance per the owner's Q4 answer. CLAUDE.md status line. FORboehpyk.md chapter (what a gate
      is, why each clause needs its own row, the honest `EXPLAIN`, the rehearsal's result). AC-18.

## Verify

- [ ] **T9:** `/verify` → reviewer PASS, AC-1…AC-18 checked (AC-16 by the owner's log entry),
      docs updated.
