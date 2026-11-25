# Technical Plan: search-benchmark

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order — which this slice mostly leaves empty, on purpose (D1).

## Pre-planning observations (2026-11-24, read from the repo, not measured)

| What | Found | Consequence |
|---|---|---|
| Indexes the search statements can use | `listing_listing`: PK, `(category_id)`, `(seller_id)`. `listing_attribute_value`: PK `(listing_id, attribute_id)`, `(attribute_id, option_id)`. `search_listing_text`: PK, GIN `document`, GIN trigram `title_folded`. `catalog_category`: PK, two slug uniques — **no `(parent_id)` index** (the subtree recursion probes `parent_id`). | Nothing on `status`, `published_at`, price, or `value_int`. Candidates in D6; none added without a failing scenario. |
| Hits order | `ORDER BY l.published_at DESC, l.id DESC LIMIT 20 OFFSET ≤ 980` | B1/B8: a top-N sort over every matching row. Cheap at 10 k; measured, not assumed. |
| Statement count | `facetSchemaFor` 1 + total 1 (+1 on fallback) + hits 1 + counts 1 + k (k ≤ 20) | B9 = 25 statements. |
| PHP-FPM pool | `docker/php/Dockerfile` copies only `php.ini`; the stock `php:8.4-fpm` `www.conf` gives `pm = dynamic`, `pm.max_children = 5`. | At 50 in flight, 45 queue. Q6. |
| PostgreSQL | `postgres:16-alpine`, stock config (`shared_buffers` 128 MB, `work_mem` 4 MB, `max_connections` 100). No `pg_stat_statements` (needs `shared_preload_libraries` + restart). `auto_explain` ships in the image and loads per database via `session_preload_libraries` (superuser to set). | Explain pass uses `auto_explain` (D4); no server restart, no extension, prod untouched. |
| Box file layout | `deploy.yml` scp's only `docker-compose.yml` + `docker/nginx/default.conf`. No Makefile, no repo on the box. | The runbook is plain `docker compose` commands; the bench override and k6 script are copied by hand for the run (D3). |
| Test DB guard precedent | `doctrine.yaml` `when@test`: `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` | AC-1's name rule accepts `_test<n>` so the seeder is testable under DAMA. |
| Trigram GUC | `%>` is the commutator of `<%` → governed by **`pg_trgm.word_similarity_threshold`** (default 0.6), not `pg_trgm.similarity_threshold` (0.3). `PostgresSearch`'s docblock says "the similarity threshold … (0.6)". | If anything is ever tuned, it is `word_similarity_threshold`. Docblock wording fixed in T9. |

## Decisions

### D1 — Zero Domain, zero Application code; Search still has no aggregate

Nothing here is a business rule. "The budget is met" is an operational fact about one box, one
image and one data shape; it is not a policy the Domain could enforce or a use case a user invokes.
The seeder is a **client** of existing use cases, so it belongs where every other driving adapter
lives (Infrastructure), next to `SentryCheckCommand`. `SearchPort`, `FacetedQuery`, the rules
S-1…S-18 and every Search VO are untouched. If tuning (D6) lands, it is a migration and/or a mapping
change — still no Domain/Application diff.

*Rejected:* a `Benchmark` bounded context (there is no ubiquitous language to protect and no
invariant); a `SeedCatalog` Application command (an Application use case nobody but a script calls is
scaffolding — the existing commands already are the API).

### D2 — ADR-0026: *Search latency is proven on a seeded benchmark database on the box; latency is the verdict, plans are the evidence* (Q1, Q2, Q5, Q9)

Records, for `phase2-gate` (15) to inherit unchanged:
1. **The budget's reading** (Q1): p95 < 200 ms **per gating scenario**, 50 clients, closed loop,
   no think time, on ≥ 10 000 published listings, server-side (bench nginx inside the Compose
   network — no Traefik/TLS/Internet), 0 failures, 100 % checks.
2. **Where** (Q2): the production box, production image, `APP_ENV=prod`, against a dropped-after
   `muzbar_bench`. Local runs are iteration. No CI job.
3. **Evidence rule** (Q5): plans are captured without planner switches; a seq scan in a scenario that
   meets the budget is recorded with its reason, not "fixed"; a seq scan on a *selective* leg
   (selected-facet `EXISTS`, text predicate) is investigated even when the budget is met.
4. **Tuning order** (D6) and the rule "no change without a failing scenario, no change kept that does
   not move it".
5. **What a CPU-bound failure means**: not a Postgres verdict; Constitution §5's Meilisearch clause is
   not triggered by it.

Amends: ADR-0002 (benchmark run, verdict), ADR-0004 (promoted columns after measurement), and — only
if an index on Listing's table is added for Search — ADR-0023 (a Search need declared in Listing's
mapping, see D6).

### D3 — Where it runs: a bench pair beside production, internal only (Q2)

`docker-compose.bench.yml`, used as `docker compose -f docker-compose.yml -f docker-compose.bench.yml`:

- **`app-bench`**: `image: ${MUZBAR_IMAGE:-muzbar-app:local}`, `env_file: .env`, then
  `environment:` (outranks `env_file:`): `APP_ENV: prod`, `APP_DEBUG: "0"`,
  `DATABASE_URL: <same server>/muzbar_bench?...`, `SENTRY_DSN: ""`. `entrypoint: []`,
  `command: ["php-fpm"]` (the worker's pattern — it publishes no assets). Networks: `default`
  (Postgres, Redis) and `bench` **with alias `app`**.
- **`nginx-bench`**: `nginx:1.27-alpine`, the **unchanged** `docker/nginx/default.conf` (its
  `fastcgi_pass app:9000` resolves to `app-bench` on the `bench` network — no second config file),
  `public_assets` mounted read-only. Network: `bench` only.
- **No `ports:`, no `traefik` network, no labels.** k6 joins the `bench` network:
  `docker run --rm --network <project>_bench -v ./bench:/bench grafana/k6:<pinned> run …`.
- Redis is shared with production (same image ⇒ same cache namespace ⇒ same values; anonymous
  `/browse` starts no session). If Q7 adds a limiter, the bench is excluded by its own env, never by
  raising the production limit.

On the dev machine the same file works with the locally built production image (`build:` target
`production`); the dev stack's Xdebug and debug kernel are never measured.

*Rejected:* a separate Compose project with its own Postgres (a second server competing for the same
RAM measures a different box; and `!reset` on Traefik labels is fragile); seeding `muzbar` (never);
publishing `127.0.0.1:8081` (works, but no port is better than a loopback port).

### D4 — The three passes

All driven by **one k6 script** `bench/search.js` reading the scenario file; mode chosen by env var.

| Pass | Clients | Per scenario | Purpose | Gate |
|---|---|---|---|---|
| `service` | 1 | 50 sequential requests over its URLs | service time p50/p95 (nothing queued) | none — diagnosis |
| `explain` | 1 | each URL once, with `auto_explain` on | the plans PostgreSQL **chooses**, with real bound values and timings | AC-9/AC-11 (evidence) |
| `load` | 50 | 10 s warm-up (tag `warmup`, no thresholds) + 30 s measured, scenarios run one after another (`startTime`) | the verdict | AC-10: `http_req_duration{scenario:Bn}` `p(95)<200` for B1–B8; `http_req_failed` `rate==0`; `checks` `rate==1` |

- **Checks** (every pass): `status === 200` and the results marker (`section.search-results` with ≥ 1
  hit, or the similar-match marker for B7). Without them an empty database "passes" (AC-8).
- **Rotation:** each VU picks the scenario's URLs round-robin from its VU id, so 50 clients spread
  over ≥ 10 URLs.
- **Explain pass setup** (runbook, superuser):
  `ALTER DATABASE muzbar_bench SET session_preload_libraries = 'auto_explain'`, `auto_explain.log_min_duration = 0`,
  `auto_explain.log_analyze = on`, `auto_explain.log_buffers = on`, `auto_explain.log_format = 'json'`.
  Each FPM request opens a new connection, so it applies to `app-bench` at once and to nothing else.
  Plans land in the `postgres` container's log; the runbook's `docker compose logs --since` + `grep`
  lines extract `Seq Scan` nodes on the two value/text tables and the top node per statement.
  `ALTER DATABASE muzbar_bench RESET ALL` before the load pass (log_analyze timing overhead would
  pollute it).
- **`ANALYZE`** (whole database) after seeding and before any pass — autovacuum's timing must not
  decide the plans (the `SearchIndexUsageTest` lesson).
- **Output:** `k6 --summary-export /bench/out/<mode>.json` (host `bench/out/`, git-ignored),
  summarised by hand into the results log. Raw plans are not committed.

### D5 — The seed: through the handlers, deterministic, shaped like a marketplace (Q3)

`muzbar:search:seed-benchmark [--listings=10000] [--seed=1]`
in `src/src/Infrastructure/Shared/Benchmark/SeedSearchBenchmarkCommand.php`.

1. **Guard** (AC-1): `self::isDisposable($connection->getDatabase())` — `/_(bench|test\d*)$/`.
   Pure static, unit-tested. No SQL anywhere in the class.
2. **Catalog** via `CreateCategoryHandler`, `DefineAttributeHandler`, `AddAttributeOptionHandler`:
   - 5 roots (Guitars, Basses, Keys & Synths, Drums, Studio & PA) → 3–4 children each → 3–4
     grandchildren each (≥ 40 leaves at depth 3), plus **one depth-4 branch** under Guitars.
   - Root attributes: **Brand** (choice; **5 000** options on Guitars and Basses, 300 elsewhere —
     defined with a starter set, the rest via `AddAttributeOption`), Condition (choice, 5), Year
     (number, unit none). Mid level: 2–3 (choice ~10, boolean). Leaf: 1–2 (number with unit, choice).
   - The depth-4 branch's lineage carries **20 choice/boolean/number attributes** (B9), lawful under
     C-21 (distinct codes along the branch).
   - A first `CategorySlugAlreadyTaken` → exit 1 "database not empty" (spec failure contract).
3. **Listings** via `DraftListingHandler` then `ReviseListingHandler` (`publish: true` for the
   published share; drafts stop after draft; withdrawn = publish then `WithdrawListingHandler`):
   - leaf drawn Zipf-like (s ≈ 1.0) over the leaves; ~500 random seller UUIDs (Listing never checks
     users — no FK, ADR-0009);
   - values for every **required** attribute and ~70 % of the rest; Brand Zipf-like (s ≈ 1.1) over
     its options; numbers in each attribute's bounds; booleans 50/50;
   - price: currency 60/25/15 % EUR/USD/SEK, log-normal-ish 50–10 000 whole units, through
     `Price::fromDecimal()` (strings — never floats; reuse the decimal string the handler parses);
   - title = brand label + model word + type word (word bank in the class); description 40–200
     words from the bank, so the text index has realistic posting lists. A fixed rare word in
     ~0.5 % of titles (B6 rare), the brand words in many (B6 common).
   - `mt_srand($seed)` + `mt_rand()` only (AC-4). The `Clock` is the system clock: every
     `published_at` falls within the run's minutes — acceptable (the top-N sort's cost does not
     depend on the spread), noted in the runbook.
   - `EntityManagerInterface::clear()` every 200 listings (AC-6; the identity map is the OOM).
4. **Scenarios** (AC-5): build B1–B9 URLs from what was just written (ids held in memory — never
   re-read): the 10 biggest leaves, the top brands, the 5 roots, a typo of a common brand
   (one transposition, verified to have no exact match by B7's own check), the B9 branch with 20
   selections + `q` + an EUR range. Printed to **stdout** as JSON `{ "B1": ["/browse", …], … }`,
   paths only — k6 prefixes the host. The production image has no host mount, so the runbook
   redirects: `docker compose … exec -T app-bench php bin/console muzbar:search:seed-benchmark >
   bench/out/scenarios.json`.
5. Progress (one line per phase, counts, elapsed seconds) goes to **stderr**
   (`ConsoleOutputInterface::getErrorOutput()`), so stdout stays valid JSON; exit 0.

Domain events released by the handlers are dispatched as usual. `ListingPublished` has no listener
today; **watch item for `phase2-gate`:** after `search-alert-match` (14) it will — the bench database
has no alerts, so no mail, but the runbook should say so.

### D6 — Tuning: only on a failing gating scenario, in this order (AC-15)

1. **Read the plan** of the failing scenario's slowest statement (explain pass).
2. **SQL-bound** (slowest statement ≥ ~50 % of service time) → candidate index matching the node,
   from the table below → re-run.
3. **Planner estimate wrong** (estimated vs actual rows ≥ 10×) → `ALTER DATABASE … SET` of the
   relevant GUC or a statistics target, applied in a migration with `format('ALTER DATABASE %I …',
   current_database())` so dev, test and prod get it — **never `SET` per request** (CLAUDE.md).
4. **Still failing on the value layer** → promotion (ADR-0004): a real column on `listing_listing`
   for that attribute. Its own ADR-0004 amendment; very likely its own slice — ask first.
5. **Service time fine, load p95 not** (queueing) → PHP-FPM pool (Q6), then box size, then the
   Q1 conversation. Never an index.

| Candidate | Serves | Where it is declared | Extra cost |
|---|---|---|---|
| `listing_listing (price_currency, price_amount)` | B5 | **Listing's XML mapping** (`listing_listing` is mapped — a hand migration alone is re-diffed as a DROP) | ADR-0023 amendment: a Search need in Listing's mapping |
| `listing_listing (status, published_at DESC, id DESC)` or partial `(published_at DESC, id DESC) WHERE (status = 'published')` | B1, B8 | Listing's XML mapping; partial `where` in PostgreSQL's normalised, parenthesised form (footgun) | same |
| `catalog_category (parent_id)` | the subtree recursion (B2, B8) | Catalog's XML mapping | — |
| `listing_attribute_value (attribute_id, value_int)` | B4 range legs, number stats | hand-written migration (table unmapped + `schema_filter`) **+ `ListingAttributeValuesSchemaTest` (AC-6 shape) update** | — |
| `INCLUDE (listing_id)` on `(attribute_id, option_id)` | index-only count legs (B2, B3) | same as above | rewrites an index; `NoDdlTest` unaffected (migration, not a handler) |
| `pg_trgm.word_similarity_threshold`, statistics target on `search_listing_text` | B7 | migration `ALTER DATABASE` | recall changes are the owner's (non-goal) |

No expression indexes (CLAUDE.md). `CREATE INDEX` without `CONCURRENTLY` is fine pre-launch (small
tables; the existing migrations' reasoning).

### D7 — The rate-limit carry-over (Q7)

Decided from B9, using one extra 30 s mixed run (25 clients B2 + 25 clients B9) only if B9 alone
looks expensive. If a limiter is added: `rate_limiter.yaml` (`browse`, sliding window, per client IP
— trusted proxies already configured), `BrowseController` consumes it, `429` with the standard
page, `ClearsRateLimiters` lists it, a functional test proves the 429, and `app-bench` sets a bench
env that disables it. Otherwise: one paragraph in ADR-0026 and the roadmap.

## Domain layer (pure PHP)

- **Aggregate / entity changes:** none. Search has no aggregate (ADR-0023) — unchanged.
- **Value objects:** none.
- **Domain events:** none raised or consumed by new code.
- **Ports (interfaces):** none. `SearchPort` unchanged.

## Application layer

- **Command/Query:** none new. The seeder calls existing commands: `CreateCategory`,
  `DefineAttribute`, `AddAttributeOption`, `DraftListing`, `ReviseListing` (`$attributes`, `$publish`
  — both required, no defaults), `WithdrawListing`.
- **Handler:** unchanged; every write runs under `CategoryTreeLock` as today (`holdSteady()` for
  Listing, `exclusively()` for Catalog).
- **Idempotency:** not idempotent by design — a second run refuses (C-3 on the first root).

## Infrastructure layer

- **Persistence:** none, unless D6 (mapping XML and/or a migration).
- **HTTP / UI:** none (unless D7: limiter in `BrowseController`).
- **Console:** `SeedSearchBenchmarkCommand` (D5) in `Infrastructure/Shared/Benchmark/`; autowired;
  injects the six handlers, `Connection` (for `getDatabase()` only) and `EntityManagerInterface`
  (for `clear()` only).
- **Ops files:** `docker-compose.bench.yml` (D3); `bench/search.js` (D4); `.gitignore` gains
  `/bench/out/`; optional
  `docker/php/zz-pool.conf` (Q6) copied in the Dockerfile `base` stage.
- **DI wiring:** none beyond autowiring/autoconfigure of the command.

## Interface boundary & input contract

- **CLI:** `--listings` int 1…1 000 000 (default 10 000); `--seed` int (default 1). A non-integer
  or out-of-range value → exit 1 with a message, before any write.
  Exit 0 success; 1 refused database / not empty / handler refusal (message printed).
- **Scenario file:** `{"B1": [path, …], …, "B9": [path]}` — relative `/browse?…` paths, query
  strings URL-encoded exactly as the GET form submits them (`f[<id>][]=`, `r[<id>][min]=`,
  `price[currency]=…`, `q=`, `page=`).
- **k6:** env `BENCH_MODE=service|explain|load`, `BENCH_BASE=http://nginx-bench`,
  `BENCH_SCENARIOS=/bench/out/scenarios.json`. Exit code = verdict for `load`.

## Data & migrations

- **None by default.** No new table, column, index or function. D6 alone may add indexes (named
  `idx_<table>_<cols>`, per existing style), each with the query it serves in the migration docblock.
- `muzbar_bench` is created by `doctrine:database:create` + `doctrine:migrations:migrate` with the
  overridden `DATABASE_URL` (extensions: same pre-check as ADR-0025's runbook), dropped afterwards.

## Test plan

- **Domain unit:** none (no Domain code). `SeedSearchBenchmarkCommandTest::isDisposable` data
  provider (AC-1) is a plain unit test — no kernel.
- **Integration (DAMA, `muzbar_test`):** run the command with `--listings=60 --seed=7` via
  `CommandTester`: counts (AC-3 scaled), determinism across two runs (AC-4 — two DAMA tests, compare
  the count maps), second run in the same test refused with exit 1 (failure contract), the source
  scan (AC-2: no SQL keyword, no table name — strip comments first, assert > 0 files scanned).
- **Functional:** every URL in the emitted scenario file → `KernelBrowser` `200` + results marker;
  B7 similar-match; B9 has `MAX_ATTRIBUTES` selections (AC-5). Uses `ClearsRateLimiters` if D7 lands.
- **By hand, recorded:** AC-7…AC-14 — local dry run first (including AC-8's empty-database failure
  and AC-6's memory), then the box.
- **Bite proof (mutations):** (a) widen the guard regex to accept `muzbar` → AC-1 test fails;
  (b) break one URL in the scenario builder (wrong option id) → AC-5 fails; (c) drop `publish: true`
  → AC-3/AC-5 fail; (d) remove the k6 body check → AC-8's empty-database run passes (proves the check
  is what fails it); (e) drop `mt_srand` → AC-4 fails.

## Risks / open questions

1. **The literal budget may be CPU-bound on a small shared VDS** (Q1). ≥ 250 req/s of Symfony + SQL
   needs several cores. The plan's job is to make that visible and attributable, not to hide it
   behind think time.
2. **Same-second `published_at`.** Every seeded listing is published within minutes; real data is
   spread over months. The sort cost does not care; a `published_at` index's correlation stats
   might. Noted in the log if that index is ever considered.
3. **Shared PostgreSQL during the run.** The bench load hits the production server (pre-launch: no
   users). After launch, `phase2-gate` must pick a quiet hour or reconsider.
4. **auto_explain needs superuser** to set `session_preload_libraries`. The box role is the image's
   `POSTGRES_USER` (superuser) — confirm with the ADR-0025 runbook's `rolsuper` query first.
5. **Seeding time on the box** (~20 k handler calls + 10 k option adds, each a locked transaction):
   estimate 5–15 min. Acceptable; the command prints progress per phase.
6. **Contradictions found in earlier docs** (fixed or amended by this slice):
   - docs/cicd.md plans a CI "Search latency benchmark" job (nightly/on demand) — replaced by the
     by-hand box run (Q2).
   - Roadmap gate: "`EXPLAIN` confirming every facet hits an index", and the `postgres-facet-search`
     skill: "a Seq Scan on `listing_attribute_value` is the failure signal" — on real data a seq scan
     can be the right plan; ADR-0026's evidence rule replaces both (Q5). Skill text updated in T12.
   - `PostgresSearch` docblock: "the similarity threshold is the server default (0.6)" — the GUC for
     `%>` is `pg_trgm.word_similarity_threshold` (0.6); `similarity_threshold` is 0.3. Wording fix.
   - PRD validation #1 names a Meilisearch container — superseded by ADR-0002; no action.
