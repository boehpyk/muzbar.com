# ADR-0026: Search latency is proven on a seeded benchmark database on the box; latency is the verdict, plans are the evidence

- **Status:** Accepted
- **Date:** 2026-11-24
- **Established by** the `search-benchmark` slice ([spec](../specs/search-benchmark/feature-spec.md)
  Q1, Q2, Q5, Q9; [plan](../specs/search-benchmark/technical-plan.md) D2–D6; owner approved the
  recommendations, 2026-11-24) — Phase 2 item 4. Inherited unchanged by `phase2-gate` (15).
- **Operationalises** Constitution §7 (the 200 ms budget) and §5 (no Meilisearch until PostgreSQL
  *demonstrably* cannot meet it on real data), and [ADR-0002](./0002-search-postgres-behind-port.md)'s
  benchmark watch item.

## Context

The PRD asks for faceted search "< 200 ms under a 50 concurrent request parallel load" on 10 000+
items. Every word of that is ambiguous until written down: *50 concurrent* could be 50 users with
think time (~25 req/s) or 50 requests always in flight (~250 req/s at a 200 ms mean — Little's law);
*200 ms* could be a mean, a p95 or a max, for the whole mix or per kind of request; and *where* could
be a laptop, a CI runner or the box. The roadmap's gate also asks for "`EXPLAIN` confirming every facet
hits an index" — but `SearchIndexUsageTest` already proves *usability* (with planner switches, on a
dozen rows), and on real data a sequential scan can be the planner's correct, fastest *choice*.

The stock `php:8.4-fpm` pool (`pm.max_children = 5`) and the stock PostgreSQL config were never
chosen by anyone, so a load failure may be a queueing failure that no index can fix.

## Decision

1. **The budget's reading.** For each **gating scenario** (B1–B8 of the spec), **p95 < 200 ms** under
   **50 clients, closed loop, no think time** (10 s warm-up, 30 s measured), on **≥ 10 000 published
   listings** after `ANALYZE`, measured **server-side** (k6 → bench nginx inside the Compose network;
   no Traefik, TLS or Internet), with **0 failed requests and 100 % body checks**. B9 (20 selections +
   text + price) is measured and reported, never gating.
2. **Where.** The **production box**, the **deployed image SHA**, `APP_ENV=prod`, against a scratch
   `muzbar_bench` database on the production PostgreSQL server, **dropped afterwards**. Local runs are
   iteration only. **No CI job** — a shared runner measures the runner.
3. **Evidence rule.** Plans are captured by `auto_explain` (per database, on `muzbar_bench` only) with
   the adapter's own statements, real bound values and **no planner switches**. A seq scan in a
   scenario that meets the budget is **recorded with its row count and reason, not "fixed"**. A seq
   scan on a *selective* leg (a selected facet's `EXISTS`, a text predicate) is investigated even
   when the budget is met. This replaces the roadmap's "every facet hits an index" wording.
4. **Attribution before tuning.** Three passes: **service time** (1 client), **explain**, **load**.
   Tuning order, only on a failing gating scenario: read the plan → SQL-bound: an index matching the
   node → planner estimate ≥ 10× off: `ALTER DATABASE … SET` in a migration (never `SET` per request)
   → value layer still failing: promotion (ADR-0004), asked first → service time fine but load p95
   not: the PHP-FPM pool, then the box, never an index. **No change without a failing scenario; no
   change kept that does not move its scenario's p95.**
5. **A CPU- or FPM-bound failure is not a PostgreSQL verdict.** It does not trigger Constitution §5's
   Meilisearch clause; the owner chooses between pool sizing, a bigger box, or amending §7 to a stated
   arrival rate — with the numbers in hand.
6. **The seed is lawful by construction.** `muzbar:search:seed-benchmark` writes only through the
   Application handlers, refuses any database whose name does not end in `_bench` or `_test<n>`, and
   refuses a non-empty one. It ships in the production image (it must, to run against the deployed SHA).

## Alternatives

- **50 users with think time** — easier to pass, but not what the PRD says; rejected as the gate,
  though a CPU-bound failure leaves it open as a §7 amendment.
- **A CI benchmark job** (docs/cicd.md had one planned) — a different machine every run; numbers that
  cannot be compared are not a verdict.
- **A SQL `generate_series` seeder** — seconds instead of minutes, but it could write rows the app
  never could, skip the text trigger's real path, and silently miss future columns (`expires_at`).
- **"Every facet hits an index" as the gate** — rewards forcing plans the planner rightly rejects.

## Consequences

- `phase2-gate` re-runs the same commands on the final query; it is a confirmation, not a discovery.
- The verdict needs a quiet box: a run with load average above the core count is repeated, not used.
- The production PostgreSQL server carries the bench load; after launch, pick a quiet hour.
- The seeder is a production command that writes: its name guard and the non-empty refusal are the
  safety, and the runbook never points it at `muzbar`.

## Results

*Filled in after the box run (task T12): date, image SHA, box cores/RAM, per-scenario numbers,
verdict, and the `/browse` rate-limit decision (Q7).*
