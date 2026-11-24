# Feature Spec: search-benchmark

> The *what & why*. Disposable: it dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `search-benchmark` — Phase 2 item **4 of 15** ([roadmap](../../roadmap.md) §Phase 2),
  "taken early on purpose": the `phase2-gate` (15) re-runs it on the final query, so the gate is a
  confirmation, not a discovery. After `search-full-text` (3); before `public-styles` (5) and any UI.
- **Bounded context(s):** **`Search`** is what is measured. **No Domain and no Application code in
  any context** (plan D1). The new code is operational Infrastructure — a seeding console command
  that is a *client* of existing Catalog and Listing use cases — plus a Compose override, a k6
  script, a runbook and a results log. Search still has **no aggregate**: it writes nothing, so it
  has no consistency boundary (ADR-0023); this slice does not change that.
- **Related PRD items:** §8 metric *Faceted Search Response Latency* ("< 200 ms under a 50
  concurrent request parallel load"), validation #1 (*Search Latency Benchmark*, 10 000+ mock
  items), FR-3 (facets "within a 200 ms response window"). Constitution §5 (no Meilisearch until
  Postgres **demonstrably** cannot meet the budget **on real data**), §7 (the budget).
- **Governing ADRs:** [ADR-0002](../../adr/0002-search-postgres-behind-port.md) (the benchmark is
  its named watch item), [ADR-0004](../../adr/0004-dynamic-category-attribute-schema.md) (promotion
  only when the benchmark demands it — "here or not at all", roadmap), [ADR-0023](../../adr/0023-search-is-a-read-only-projection-over-catalog-and-listing-tables.md)
  (one matching fragment, one place to tune), [ADR-0024](../../adr/0024-price-is-compared-only-within-one-currency.md)
  (`(price_currency, price_amount)` is the index shape *if* wanted), [ADR-0025](../../adr/0025-full-text-search-trigger-maintained-text-table-parser-built-query.md)
  (planner choice for the text legs; GUC tuning via `ALTER DATABASE`, never per request).
  **New: ADR-0026** (plan D2) — what "the budget is met" means, operationally.
- **Status:** Approved 2026-11-24 (plan as written; owner took every recommendation, Q1–Q10). Box vCPU/RAM still to be supplied for Q2.
- **Date:** 2026-11-24

> **What this slice teaches, beyond the feature.**
>
> 1. **A benchmark that measures empty pages passes.** Every request is checked for `200` *and* a
>    non-empty result (or, for the fallback scenario, a similar-match page); the scenario list is
>    itself tested through `KernelBrowser` before any load is applied. Same rule as the suite: no
>    assertion that cannot fail.
> 2. **Usability is not choice.** `SearchIndexUsageTest` forces the planner's hand with switches on
>    a dozen rows. Here the planner chooses freely on 10 000 `ANALYZE`d listings, and a seq scan
>    may be *the right answer* (a Brand facet over 80 % of the value table). Latency is the verdict;
>    plans are the evidence for *why* (plan D2).
> 3. **Little's law decides before any index does.** 50 requests in flight with no think time means
>    throughput ≥ 50 / 0.2 s = 250 req/s at the *mean*. That is a CPU and PHP-FPM question as much as
>    a SQL one — and stock `php:8.4-fpm` runs **`pm.max_children = 5`**, which nobody in this repo
>    chose (the "chosen, not inherited from driver defaults" rule, one layer up). So the run separates
>    **service time** (1 client) from **load latency** (50 clients) and SQL time (auto_explain),
>    and an index is added only when the evidence points at SQL.

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Benchmark database** | `muzbar_bench`: a scratch database on the same PostgreSQL server, migrated like any other, seeded, measured, **dropped** afterwards. The seeder refuses any database whose name does not end in `_bench` or `_test<n>`. | `muzbar` (dev/prod data), `muzbar_test` (DAMA, the suite's) |
| **Seed** | The deterministic synthetic catalogue + listings written **through the existing Application handlers** (`CreateCategory`, `DefineAttribute`, `AddAttributeOption`, `DraftListing`, `ReviseListing`, `WithdrawListing`). `--seed` fixes the random stream. | a Foundry fixture (dev-only, bypasses L-2) |
| **Scenario** (B1…B9) | One named kind of `/browse` request, rotated over several real URLs the seeder emits (so no single plan or cache line is measured). | a Search *rule* S-n |
| **Gating scenario** | B1–B8. Its numbers decide the verdict. B9 (worst case) is measured and reported only. | — |
| **Service time** | Latency at **1 client**, 50 sequential requests per scenario: what one request costs with nothing queued. | load latency |
| **Load run** | **50 clients, closed loop, no think time** (every client sends its next request when the previous answers), per scenario: 10 s warm-up (not measured) + 30 s measured. | an open-loop arrival rate, users with think time (Q1) |
| **Explain pass** | One request per scenario URL with PostgreSQL's `auto_explain` logging `ANALYZE, BUFFERS` JSON plans for `muzbar_bench` only — the adapter's **own** statements with **real** bound values and **no planner switches**. | `SearchIndexUsageTest` (switches, a dozen rows: usability) |
| **Verdict** | PASS when every gating scenario meets AC-10 on the **box**; recorded in docs/infrastructure.md §*Search: latency benchmark*. | a green local run (iteration only, Q2) |

### Scenarios

Each rotates over ≥ 10 URLs (where the data allows) built from the seeded ids; page 1 unless said.

| # | Request | Exercises |
|---|---|---|
| B1 | `/browse` — no category | every published listing, newest first; no facets |
| B2 | a **top-level** category | the largest subtree; unselected counts over it |
| B3 | a leaf + **one Brand** option selected | the 5 000-option choice facet; disjunctive count (one extra statement) |
| B4 | a leaf + one choice, one boolean, one number range | three `EXISTS` legs; 3 + 1 count statements |
| B5 | a top-level category + price range in EUR | `l.price_currency`/`price_amount` (unindexed today) |
| B6 | `q=` a common term (≥ 5 % of listings) and a rare one | exact match, `@@` on the GIN document index |
| B7 | `q=` a misspelling with **no** exact match | S-18 fallback: total twice, `%>` on the trigram index |
| B8 | B2 at `page=50` (`MAX_PAGE`, offset 980) | deep `OFFSET` |
| B9 | the deep branch with **20** selections (`MAX_ATTRIBUTES`) + `q` + price | the worst case: 25 statements, 19 `EXISTS` per count (roadmap carry-over) |

## User story

As **the owner**, I want **a repeatable, isolated measurement of `/browse` on 10 000+ realistic
listings at 50 concurrent requests, with the plans PostgreSQL actually chooses**, so that **I know —
before any search UI is built on the query — whether the Constitution's 200 ms budget holds, and if
not, whether the fix is an index, a promoted column, a GUC, the PHP-FPM pool, or the box.**

## In scope

- A **seeding command** that builds a realistic tree, schema and 10 000 published listings (+ drafts
  and withdrawn ones) **through the Application handlers**, deterministically, into a disposable
  database only, and emits the scenario URL list.
- A **Compose override** (`docker-compose.bench.yml`): a bench PHP-FPM + nginx pair running the
  **production image** (`APP_ENV=prod`) against `muzbar_bench`, reachable only on an internal
  network — no `ports:`, no Traefik.
- A **k6 script** (pinned image, run with `docker run`) for the service-time pass, the load run and
  the explain pass, with thresholds that set the exit code.
- The **explain pass** via `auto_explain`, configured per database on `muzbar_bench` only.
- A **runbook and results log** in docs/infrastructure.md; the **verdict** on the box.
- **Tuning, only where the evidence points** (plan D6): an index, `ALTER DATABASE … SET`, or
  promotion (ADR-0004) for SQL; an explicit PHP-FPM pool for queueing (Q6). Each re-measured.
- The **`/browse` rate-limit decision** (roadmap carry-over from `search-browse`'s `/verify`), from
  B9's numbers (Q7).
- **ADR-0026**; ADR-0002 and ADR-0004 amendments recording the results.

## Non-goals (explicit — hold the line)

- **No change to search behaviour or results.** No new rule, no new port method, no query rewrite
  that changes what a page shows. A tuning change that alters a result is out of scope by definition.
- **No CI job.** A shared GitHub runner measures the runner (Q2). docs/cicd.md's planned
  "Search latency benchmark" job is amended, not built.
- **No Meilisearch** (Constitution §5), unless SQL itself — not PHP, not FPM — cannot meet the budget
  with every candidate in plan D6 tried; that would be a new ADR, not this slice.
- **No keyset pagination.** `MAX_PAGE = 50` bounds `OFFSET` at 980; B8 measures it. Only if B8 fails.
- **No relevance or recall tuning.** `pg_trgm.word_similarity_threshold` and `FALLBACK_BELOW` stay
  unless *latency* demands a change, and a recall change is the owner's call, not a tuning step.
- **No seeding of `muzbar` (dev) or the production database**, ever; no fake listings visible at
  `muzbar.com`.
- **No end-to-end TLS/Traefik/Internet timing.** The bench nginx is hit from inside the Compose
  network; the result is server latency. Traefik and the network are outside the budget's control.
- **No load testing of anything but `/browse`** (`/listings/{id}`, sell pages: later, if ever).

## Acceptance criteria (the Definition of Done checklist)

### A. The seed (integration, DAMA, against `muzbar_test`)

- [ ] **AC-1: Refuses a non-disposable database.** The command exits `1` and writes **nothing**
      unless the connection's database name matches `/_(bench|test\d*)$/`. `muzbar`, `muzbar_prod`,
      `bench_muzbar`, `muzbar_bench2` are refused (unit-tested on the pure name check);
      `muzbar_bench`, `muzbar_test`, `muzbar_test3` are accepted.
- [ ] **AC-2: Lawful data only.** Every row is written through an Application handler — the command
      contains no SQL and names no table (an isolation-style source scan; the database name comes
      from `Connection::getDatabase()`). So
      every listing is on a leaf (L-2), every value is in force (L-9), every published listing is
      complete (L-13), and `search_listing_text` is filled by the trigger, not by the seeder.
- [ ] **AC-3: Shape.** `--listings=N` (default **10 000**) yields exactly **N published**, plus
      **⌊N/10⌋ drafts** and **⌊N/20⌋ withdrawn**. The tree has **5** roots, depth ≤ `MAX_DEPTH`
      (4), ≥ **40** leaves, and one branch whose lineage holds **≥ 20** filterable attributes. Two
      Brand attributes hold **5 000** options each. Prices are `EUR`/`USD`/`SEK` ≈ 60/25/15 %.
      Leaves and Brand options are drawn from a skewed (Zipf-like) distribution, so the largest leaf
      holds ≥ 10 × the median leaf's listings and the top 10 brands ≥ 30 % of Brand values.
- [ ] **AC-4: Deterministic.** Two runs with the same `--seed` and `--listings` into empty databases
      produce identical counts per category, per option label and per currency (ids differ:
      UUIDv7). Asserted on a small `N`.
- [ ] **AC-5: The scenario list is real.** The command prints a JSON scenario list to stdout (progress
      to stderr) (B1–B9, each with
      ≥ 1 URL, ≥ 10 where the seeded data allows). In the test, after a small seed, **every emitted
      URL** answers `200` through `KernelBrowser` with ≥ 1 hit; B7's URLs render a similar-match page
      (exact total `0`); B9's URL carries exactly `FilterRequest::MAX_ATTRIBUTES` selections.
- [ ] **AC-6: Bounded memory.** Seeding 10 000 listings stays under the CLI `memory_limit`
      (the EntityManager is cleared at least every 200 listings). Checked by the local run (AC-13),
      not by the suite.

### B. The bench stack (by hand, recorded)

- [ ] **AC-7: Isolated.** `docker-compose.bench.yml` adds `app-bench` (production image,
      `APP_ENV=prod`, `APP_DEBUG=0`, `DATABASE_URL` → `muzbar_bench`, `SENTRY_DSN=` empty) and
      `nginx-bench` (the **unchanged** `docker/nginx/default.conf`), with **no `ports:`** and no
      Traefik network or labels. `docker compose config` shows both; `muzbar_bench` is the only
      database `app-bench` can name.
- [ ] **AC-8: The checks bite.** Every k6 request checks status `200` **and** the results marker the
      page renders when it has hits (or, for B7, the similar-match marker). Pointing the run at an
      empty `muzbar_bench` (migrated, not seeded) **fails** the run — done once, recorded.
- [ ] **AC-9: Explain pass captures the adapter's own statements.** With `auto_explain` enabled on
      `muzbar_bench` only (`ALTER DATABASE muzbar_bench SET …`), one request per scenario URL logs a
      JSON plan for every statement it sends; the count matches the statement budget (≤ 5 + k). The
      settings are reset (`ALTER DATABASE muzbar_bench RESET ALL`) before the load run, and never
      touch `muzbar`.

### C. The verdict (on the box, recorded)

- [ ] **AC-10: The budget.** On the box, on `muzbar_bench` seeded with `--listings=10000`, after
      `ANALYZE`, for **each gating scenario B1–B8** under the load run (50 clients, closed loop, no
      think time, 30 s measured after 10 s warm-up): **p95 < 200 ms**, **0 failed requests**,
      **100 % checks**. k6 exits `0`. (Interpretation of "50 concurrent": Q1 — recorded in ADR-0026.)
- [ ] **AC-11: Evidence recorded.** For every scenario the log records: service-time p50/p95,
      load p50/p95/max, requests/s, and from the explain pass the slowest statement's execution time
      and its top plan node. Every `Seq Scan` on `listing_attribute_value` or `search_listing_text`
      in a gating scenario is recorded **with its row count and the reason the planner chose it**
      (ADR-0026: a seq scan that meets the budget is evidence, not a failure). For B7, the trigram
      node's **estimated vs actual rows** (ADR-0025 asked for it).
- [ ] **AC-12: B9 and the rate limit.** B9's service time and load p95 are recorded, and the
      carry-over is closed with a written decision (Q7): a per-IP limiter on `/browse` (on the
      `cache.rate_limiter` pool, added to `ClearsRateLimiters`, excluded or raised for the bench run)
      or "not needed, because …", in the roadmap and ADR-0026.
- [ ] **AC-13: Local first, box decides.** The same commands run on the dev machine (iteration) and
      on the box (verdict); the log says which is which. The box run uses the deployed image SHA.
- [ ] **AC-14: Clean up.** After the box run `muzbar_bench` is dropped and the bench containers
      removed; `SELECT datname FROM pg_database` no longer lists it (the restore rehearsal's count
      diff must not meet it). Recorded.

### D. Tuning (only if AC-10 fails — plan D6)

- [ ] **AC-15: Evidence before change.** Each change names the failing scenario, the statement and
      plan node it targets (from AC-11), and the before/after numbers of a full re-run. A change that
      does not move its scenario's p95 is reverted. No change without a failing scenario.
- [ ] **AC-16: Results unchanged.** After any tuning change the full suite is green — including
      `BrowseListingsTest`, `SearchIndexUsageTest`, `NoDdlTest`, the AC-6 shape tests of
      `listing_attribute_value` and `search_listing_text` (updated if an index lands there) — and
      `make migration.make` produces **no** diff.

### E. Docs and decisions

- [ ] **AC-17:** ADR-0026 accepted (plan D2); ADR-0002 amended (benchmark run, verdict, link to the
      log); ADR-0004 amended (promoted columns: still zero, or which and why); docs/cicd.md's
      benchmark-job paragraph amended to "by hand on the box, see runbook"; roadmap item 4 ticked
      with the numbers; CLAUDE.md status line + any new footgun; FORboehpyk.md chapter.
- [ ] **AC-18:** `make check` green; `/verify` reviewer PASS.

## Failure contract

| Condition | Expected behaviour |
|---|---|
| Seeder pointed at a non-disposable database | Exit `1`, one line naming the database and the rule, **no write** (AC-1). |
| Seeder run into a non-empty `muzbar_bench` | Exit `1` ("drop and re-create it") — a second seed on top would double the data and break AC-4. **No table is read to check it** (`ListingIsolationTest` admits table names only in their owner's and Search's Infrastructure): the first `CreateCategory` is refused by C-3 (`CategorySlugAlreadyTaken` on the first root), and the command reports that refusal as "not empty". |
| A handler refuses during seeding (a seeder bug: e.g. a value not in force) | The command stops on the first refusal with the domain exception's message; the database is left for inspection, then dropped. Never `catch` and continue — a quietly smaller seed measures less. |
| `muzbar_bench` cannot be created/migrated on the box (role lacks `CREATEDB`, extensions) | Stop; the runbook's pre-check (`rolsuper`/`rolcreatedb`) is logged first, like ADR-0025's. No bench run against any other database. |
| `auto_explain` cannot be enabled (`session_preload_libraries` needs superuser) | Explain pass falls back to `EXPLAIN (ANALYZE, BUFFERS)` replays of statements copied from one `auto_explain`-free request's SQL log in **local** dev, and the box log says so. Not silently skipped. |
| k6 checks fail (non-200, empty page, 429) | Run fails (non-zero exit); the verdict is FAIL regardless of latency. A 429 means a limiter is measuring itself — exclude the bench from it, never raise the global limit. |
| A gating scenario misses AC-10 | Verdict FAIL, recorded; plan D6's order. The deploy of other slices is not blocked (no production code changed), but `public-styles`/`search-ui` wait for the owner's decision. |
| The box is under unrelated load during the run (samolit, backups) | The run is repeated; the log records the time and `uptime` load averages before and after. A single run with load average above the core count is not a verdict. |
| Bench app errors (500s) | Count as failed requests (AC-10); visible in the `app-bench` container log. **Not** sent to Sentry (`SENTRY_DSN` empty) — bench noise in the production project would hide real events. |

## Open questions for the owner

Each has a recommendation; the plan is written as if every recommendation is accepted.

- **Q1 — What does "50 concurrent" mean?** (a) **50 clients, closed loop, no think time** — the
  PRD's literal wording ("a 50 concurrent request parallel load"), ≈ 250 req/s at a 200 ms mean;
  (b) 50 users with 1–3 s think time (≈ 25 req/s); (c) an open-loop arrival rate. **Recommend (a)
  as the gate**, recorded in ADR-0026, **plus** the service-time pass so a failure can be attributed.
  Pre-decided consequence: if (a) fails while service time and SQL are well inside budget (CPU/FPM
  saturation), no index and no Meilisearch can fix it — the owner chooses between pool sizing (Q6),
  a bigger box, or amending Constitution §7 to a stated arrival rate, **with the numbers in hand**.
- **Q2 — Where does the verdict run?** Box (real CPU, real neighbours, pre-launch so no users to
  hurt) / dev machine / CI runner. **Recommend: the box decides; local for iteration; no CI job**
  (a GitHub runner is a different machine every run; amend docs/cicd.md). Needs: box vCPU count
  and RAM, for the log and for Q6.
- **Q3 — Seed through handlers or with SQL?** SQL `generate_series` is ~100 lines and seconds to
  run; handlers take minutes. **Recommend handlers**: the data is lawful by construction (a failure
  cannot be blamed on rows the app could never write), the trigger fills the text table as in
  production, and `phase2-gate` (15) re-runs it after `listing-expiry` adds `expires_at` — the
  handlers get that for free; a SQL seeder would silently seed listings that never expire or are
  all expired. It is also the DDD lesson: a seeder is just another client of the use cases.
- **Q4 — Load tool.** k6 in Docker (pinned tag, per-scenario thresholds set the exit code, one JS
  file, nothing installed) / `ab` or `hey` (one URL per run, no checks on body) / a PHP
  HttpClient command (measures PHP measuring PHP). **Recommend k6.** Note: a `.js` file in the repo
  is not a Node build step (ADR-0006 is about the asset pipeline); it runs only inside the k6 image.
- **Q5 — "EXPLAIN confirming every facet hits an index" (roadmap gate wording).** On real data a seq
  scan of the value table can be the correct, fastest plan (an unselected Brand count at a root reads
  most of it anyway). **Recommend: latency is the gate, plans are evidence** — selective legs (a
  selected facet's `EXISTS`, the text predicates) are expected on their indexes and a seq scan there
  is investigated; a seq scan on a scenario that meets AC-10 is recorded with its reason (AC-11),
  not "fixed". Record in ADR-0026; amend the roadmap gate's wording to point at it.
- **Q6 — PHP-FPM pool.** The image inherits `pm = dynamic`, `pm.max_children = 5` from the stock
  `www.conf`: at 50 in flight, 45 wait in the listen backlog, and load p95 ≈ 10 × service time.
  **Recommend: measure first as-is (that number is a finding), then make the pool explicit** in a
  tracked `docker/php/zz-pool.conf` (`pm = static` or `dynamic`, `max_children` sized as
  ⌊(RAM budget for muzbar) / (measured child RSS)⌋, capped by PostgreSQL `max_connections` − 20).
  It ships to prod with the next image — infrastructure.md footgun #6 is about exactly this cap.
  Owner gives the RAM budget for muzbar on the shared box.
- **Q7 — `/browse` rate limit (carry-over).** **Recommend:** add a per-IP limiter only if B9 at 50
  clients produces failures, or its p95 exceeds **1 000 ms**, or running B9 concurrently with B2
  pushes B2 past budget (one quick mixed run); otherwise record "not needed yet" and re-ask at
  `search-ui` (7), when the page becomes easy to hammer from a browser.
- **Q8 — Volume.** **Recommend: 10 000 published is the gate; one extra 50 000 run on the box,
  ungated, recorded** — it tells `phase2-gate` how much headroom exists and whether any scenario
  grows super-linearly (B1/B2/B8's top-N sort, B2's counts). Cost: one more seed (tens of minutes).
- **Q9 — ADR-0026 or amendments only?** **Recommend ADR-0026**: the interpretation of the budget
  (Q1), where it is measured (Q2), the evidence rule (Q5) and the tuning order are inherited by
  `phase2-gate` and would otherwise be re-argued there. Results go into ADR-0002/0004 amendments.
- **Q10 — The seeder ships in the production image.** It must, to run on the box against the
  deployed SHA. **Recommend yes**, guarded by AC-1 (name rule) and the non-empty check; it lives in
  `Infrastructure/Shared/Benchmark/` (not `Infrastructure/Search/`, whose fence forbids writes).
