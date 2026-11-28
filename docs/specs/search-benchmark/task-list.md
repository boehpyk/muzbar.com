# Task List: search-benchmark

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/search-benchmark`. Run `make check` before each commit. Check off as you go.
> **Domain and Application are empty on purpose** (plan D1). **Owner answers:** pending (Q1–Q10);
> tasks assume every recommendation is accepted.

## Decision
- [x] T0: `/adr` → **ADR-0026** *Search latency is proven on a seeded benchmark database on the
      box; latency is the verdict, plans are the evidence* (plan D2: budget reading, where, evidence
      rule, tuning order, what a CPU-bound failure means). Results are filled in at T12.

## Domain
- (none — Search has no aggregate; no VO, event or port changes)

## Application
- (none — the seeder calls the existing Catalog and Listing commands)

## Infrastructure
- [x] T1: `SeedSearchBenchmarkCommand` skeleton — options, `isDisposable()` guard (AC-1), the
      C-3 "not empty" refusal, stderr progress; `SeedSearchBenchmarkCommandTest` unit cases for the
      name rule.
- [x] T2: Catalog phase — tree (5 roots, ≥ 40 leaves, one depth-4 branch), attributes incl. the two
      5 000-option Brands and the 20-attribute B9 lineage, via the handlers (plan D5.2).
- [x] T3: Listing phase — draft/publish/withdraw shares, Zipf-like leaves and brands, values for
      every required attribute, prices as decimal strings, word-bank text, `mt_srand`, EM `clear()`
      every 200 (plan D5.3).
- [x] T4: Scenario builder — B1–B9 URLs from the ids in memory, JSON to stdout (plan D5.4).
- [x] T5: `docker-compose.bench.yml` — `app-bench` (alias `app` on `bench`), `nginx-bench`, no
      ports, no Traefik, `SENTRY_DSN` empty (plan D3, AC-7). `.gitignore` `/bench/out/`.
- [x] T6: `bench/search.js` — three modes, rotation, checks, per-scenario thresholds, summary export
      (plan D4). k6 image pinned to an exact tag ≥ 2 weeks old.
- [x] T7: Runbook in docs/infrastructure.md §*Search: latency benchmark* — pre-check (`rolsuper`,
      extensions), create + migrate + seed + `ANALYZE`, the three passes, `auto_explain`
      on/`RESET ALL`, plan-extraction `grep`s, drop + cleanup (AC-14), and an empty results-log table
      (date · where · image SHA · cores/RAM · rows · per scenario service p50/p95, load p50/p95/max,
      req/s, failures · slowest statement + top node · verdict · changes).

## Tests (qa)
- [x] T8: Integration + functional — AC-2 source scan, AC-3 scaled counts, AC-4 determinism, second
      run refused, AC-5 every emitted URL → 200 + results marker (B7 similar, B9 20 selections).
      Bite proof (a)–(e) from the plan's test plan, noted in the commit message.

## Measure (by hand, recorded)
- [x] T9: Local run — AC-8 (empty database fails the run), AC-6 (memory), all three passes; fix the
      `PostgresSearch` docblock's trigram GUC wording (`word_similarity_threshold`).
- [x] T10: Box run — AC-9, AC-10, AC-11, AC-13 at 10 000; one ungated 50 000 run (Q8); B9 and the
      rate-limit decision (AC-12, plan D7); cleanup (AC-14). Everything logged.
      *Done 2026-11-25: FAIL, CPU-bound (d.5); 50 000 run skipped — CPU is the verdict, more rows
      only restate it. AC-10 as amended (10 req/s) **deferred** — re-run due before search-ui (7).*
      *Re-run 2026-11-28: **PASS** at 10 req/s (B1–B8 p95 46–97 ms, 0 dropped).*

## Tuning (only if T10 fails — plan D6; one task per change, each re-measured)
- [x] T11a: PHP-FPM pool made explicit (`docker/php/zz-pool.conf`, Q6) — sized from measured child
      RSS and the owner's RAM budget; re-run T10.
      *Measured on the box (12 children): 2 % slower. Not shipped; `zz-pool.conf` deleted.*
- [x] T11b…: (none — not SQL-bound) one candidate from plan D6's table per task (mapping XML or hand migration + shape-test
      update), before/after numbers in the commit message (AC-15, AC-16). Reverted if it does not
      move its scenario.
- [x] T11z: (only if D7 says so) `/browse` per-IP limiter + `ClearsRateLimiters` + 429 test + bench
      exclusion.
      *30/min per IP (sliding), `BROWSE_RATE_LIMIT`; test env 1 000 000, bench 1 000 000. Bite: with the
      check disabled, the 429 and before-the-search tests fail.*

## Verify
- [x] T12: Docs — ADR-0026 results; ADR-0002 + ADR-0004 amendments (ADR-0023 if a Listing-mapped
      index landed); docs/cicd.md benchmark paragraph; roadmap item 4 ticked with numbers and the
      gate wording pointed at ADR-0026; `postgres-facet-search` skill's seq-scan line; CLAUDE.md
      status + footguns (FPM default pool, auto_explain per database, the bench never on `muzbar`);
      FORboehpyk.md chapter (Little's law, usability vs choice, a benchmark of empty pages passes).
- [x] T13: `/verify` → reviewer PASS, all acceptance criteria checked, docs updated.
      *2026-11-25: PASS in two iterations (MAJOR: ADR-0026 claimed a 10 req/s result that did not
      exist). AC-10 (amended) **deferred**, not met — box re-run before search-ui (7).*
      *AC-10 (amended) **met 2026-11-28**: PASS on the box.*
