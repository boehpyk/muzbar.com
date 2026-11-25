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
- The verdict needs a quiet box: a run whose load average *before it starts* is above the core count
  is repeated, not used (the run itself drives it above — that is the measurement).
- The production PostgreSQL server carries the bench load; after launch, pick a quiet hour.
- The seeder is a production command that writes: its name guard and the non-empty refusal are the
  safety, and the runbook never points it at `muzbar`.

## Results

**2026-11-25, box, image `5ec2bb6`** (the branch build; identical to `main` `0083f89` but for the
seeder), Hetzner 4 shared vCPU / 7.7 GB, no swap, load 0.22 before the run. 10 000 published
listings (+1 000 drafts, 500 withdrawn). Full table: docs/infrastructure.md §*Search: latency
benchmark*, results log.

| | B1 | B2 | B3 | B4 | B5 | B6 | B7 | B8 | B9 (reported) |
|---|---|---|---|---|---|---|---|---|---|
| Service p95, 1 client | 40 | 67 | 65 | 81 | 57 | 57 | 102 | 67 | 1 728 ms |
| Load p95, 50 clients | 618 | 994 | 887 | 1 078 | 864 | 871 | 1 413 | 1 021 | 34 248 ms |
| Requests/s under load | 91 | 58 | 62 | 51 | 68 | 62 | 38 | 58 | ~1 |

**Verdict: FAIL — CPU-bound (decision 5), not a PostgreSQL verdict.** 0 failed requests, 100 %
checks. Every gating scenario reached ~85 % of what 4 cores serve at its service cost; 50 clients
÷ that rate is the latency (Little's law: B1 50 ÷ 91 ≈ 550 ms, measured p50 546 ms). The slowest
statement of any gating scenario executes in 57.5 ms. The budget's reading (decision 1) needs
~250 req/s, about 3× this box. Tuning order (decision 4) stops at step 1: no index moves CPU-bound
latency, and a bigger PHP-FPM pool (12 children vs the image's 5) was 2 % **slower** — the queue
moved into the run queue (load 12.5 vs 5.8). The pool stays at the image default.

**The owner's choice (decision 5), 2026-11-25: a different reading of the budget** — see the
amendment below. The 50-client run above stays as the record of what this box can do.

**Rate limit (Q7): yes.** B9 (20 selections, `MAX_ATTRIBUTES`) costs 1.56 s per request, 1.42 s of
it **planning** (`bind`) and 26 ms executing; under load one takes ~28 s. Any visitor can build that
URL, so `/browse` gets a per-IP limiter (plan D7, task T11z). Making the statements cheaper to plan
is a separate, later slice.

## Amendment 2026-11-25 — the load is 10 requests per second, arriving (owner, after the box run)

Decision 1's load changes from *50 clients, closed loop* to **10 requests/s per scenario, open
model** (k6 `constant-arrival-rate`: arrivals do not wait for answers, as real visitors don't).
Everything else in decision 1 stands: p95 < 200 ms per gating scenario, 10 s warm-up + 30 s
measured, ≥ 10 000 listings, 0 failures, 100 % checks — plus **0 dropped arrivals** per gating
scenario (k6 caps in-flight requests at 50; an open model shows overload as skipped arrivals, not
slow answers, so a skip fails the run).

*Why:* the owner does not expect 50 visitors searching at once, every one clicking again the instant
a page loads; that reading needs ~250 req/s, three times what 4 cores serve (decision 5's
CPU-bound outcome, measured above). 10 req/s is ~860 000 searches a day and uses ~15 % of the box at
the measured service times, so the budget again measures a page's cost, not the CPU's queue.

*What it gives up:* the PRD's literal "50 concurrent" (Context). The PRD's wording is not changed;
Constitution §7's budget row points here. Revisit when traffic approaches 10 req/s — the 50-client
numbers above say where the box tips over (~40–90 req/s by scenario). B9 and the rate limit are
unaffected: one B9 is ~1.5 s of CPU at any load.

*Results:* **pending — not yet run.** The 10 req/s box re-run (feature-spec AC-10 as amended) is
deferred by the owner; it is due **before `search-ui` (7)** and at the latest at `phase2-gate`
(15), and goes into docs/infrastructure.md's results log. Until then the amended budget is an
estimate from the measured service times, not a measurement. The re-run must also confirm k6's
summary shows every `{bn:Bn,phase:measure}` threshold with a non-zero count — a threshold with no
samples passes vacuously (the load mode's per-scenario gating has never run).
