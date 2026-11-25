# Box run (T10) — search benchmark, step by step with explanations

> The explained, one-off walkthrough of docs/infrastructure.md §*Search: latency benchmark* for the
> first box run (2026-11-25). The canonical runbook stays there; this dies with the spec.
> Box: Hetzner, 4 shared vCPU, 7.7 GB RAM, ~5.3 GB available, **no swap**, four tenants.
>
> About 40–60 minutes, mostly waiting. Nothing touches production's data or containers **as long as
> you stick to these commands**.

---

## 0. Build the image (GitHub, ~5 min)

1. GitHub → **Actions** → **Deploy** → **Run workflow** → branch **`feature/search-benchmark`** → Run.
2. Wait for **`build`** to go green — it pushes `ghcr.io/boehpyk/muzbar.com:<branch head sha>`
   (at the time of writing `c194d1b938515e99271b7d90e7e5cf35c285a83d`; check with
   `git rev-parse origin/feature/search-benchmark`).
3. When **`deploy`** asks for approval → **reject it.** Production stays on its current image.

*Why:* the box never builds, it only pulls immutable images. The seeder must be in the image, and the
image must be the production build (prod config, no Xdebug) — otherwise we measure the wrong thing.
Side effect: `:latest` moves to the branch build; nothing on the box uses `:latest` (`.env` pins a SHA).

---

## 1. Copy the three bench files to the box (laptop, repo root)

```bash
ssh <box> 'mkdir -p /home/muzbar-deploy/muzbar.com/bench /home/muzbar-deploy/muzbar.com/docker/php'
scp docker-compose.bench.yml <box>:/home/muzbar-deploy/muzbar.com/
scp bench/search.js          <box>:/home/muzbar-deploy/muzbar.com/bench/
scp docker/php/zz-pool.conf  <box>:/home/muzbar-deploy/muzbar.com/docker/php/
```

*Why:* the deploy only ships `docker-compose.yml` + nginx config. These are deleted again at the end.

---

## 2. Shell setup on the box

```bash
ssh <box>
cd /home/muzbar-deploy/muzbar.com

export MUZBAR_IMAGE=ghcr.io/boehpyk/muzbar.com:<the sha from step 0>
B="docker compose -f docker-compose.yml -f docker-compose.bench.yml"
K6="docker run --rm --user $(id -u):$(id -g) --network muzbar_bench -v $PWD/bench:/bench grafana/k6:1.0.0 run --quiet"
mkdir -p bench/out
```

- **`MUZBAR_IMAGE`** — a shell export beats `.env`, so the bench containers use the branch image.
  ⚠️ While it is exported, **never run a bare `docker compose up`** — it would switch production to
  the branch image. Only ever `up` the two bench services by name.
- **`$B`** — production's compose file + the bench override (`app-bench`, `nginx-bench`).
  ⚠️ **Never `$B down`** — that stops the whole production stack.
- **`$K6`** — k6 in a throwaway container on the bench's private network; `--user` lets it write
  results into `bench/out` (k6's default user can't).

---

## 3. Pre-check (write the output down)

```bash
docker compose exec app php bin/console dbal:run-sql "SELECT rolsuper, rolcreatedb FROM pg_roles WHERE rolname = current_user"
nproc; free -m; uptime
```

- `rolsuper = t` → step 7 can enable `auto_explain` (needs superuser). `f` → skip step 7, note it.
- Both `f` → **stop**: the role cannot create the scratch database.
- `uptime` is the "is the box quiet?" baseline. If the load average is already near **4**, wait —
  other tenants busy means bad numbers.

---

## 4. Start the bench pair, create the scratch database

```bash
$B up -d --no-deps app-bench nginx-bench
$B exec -T app-bench php bin/console doctrine:database:create
$B exec -T app-bench php bin/console doctrine:migrations:migrate -n
```

A second PHP-FPM (`app-bench`) and nginx (`nginx-bench`) on the branch image, on a private network
`muzbar_bench` — no ports, no Traefik, unreachable from the Internet. `app-bench`'s `DATABASE_URL` is
hard-wired to **`muzbar_bench`**, a new database on production's PostgreSQL server; `muzbar` is never
touched. `--no-deps`: compose leaves production's services alone.

---

## 5. Seed 10 000 listings, then `ANALYZE` (~5–10 min)

```bash
$B exec -T app-bench php bin/console muzbar:search:seed-benchmark --listings=10000 --seed=1 > bench/out/scenarios.json
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d muzbar_bench -c ANALYZE'
```

- 5-root / 45-leaf tree, attributes (two 5 000-option Brands), 10 000 published + 1 000 drafts +
  500 withdrawn — every row through the real application handlers, so it is data the app itself
  could have written.
- Progress → terminal (stderr). The **scenario URL list** (B1–B9) → `bench/out/scenarios.json`.
- It refuses any database not named `*_bench`, and a non-empty one — it cannot double-seed.
- `ANALYZE` refreshes the planner's statistics, so plans reflect the real data, not autovacuum timing.

---

## 6. Service pass — one client, nothing queued (~1–2 min)

```bash
$K6 -e BENCH_MODE=service --summary-export /bench/out/service.json /bench/search.js
```

50 requests per scenario, one at a time: **what one request costs** with nothing waiting ahead of
it. No gate — diagnosis. If the load pass says 900 ms and this says 50 ms, 850 ms is waiting, not work.
Every request checks status 200 **and** that the page has results — an empty or broken page fails
the run, so "fast because empty" cannot pass.

---

## 7. Explain pass — the plans PostgreSQL picks (~1 min)

```bash
# auto_explain ON, for muzbar_bench only
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres' <<'SQL'
ALTER DATABASE muzbar_bench SET session_preload_libraries = 'auto_explain';
ALTER DATABASE muzbar_bench SET auto_explain.log_min_duration = 0;
ALTER DATABASE muzbar_bench SET auto_explain.log_analyze = on;
ALTER DATABASE muzbar_bench SET auto_explain.log_buffers = on;
ALTER DATABASE muzbar_bench SET auto_explain.log_format = 'json';
SQL

SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
$K6 -e BENCH_MODE=explain /bench/search.js
docker compose logs --no-log-prefix --since "$SINCE" postgres > bench/out/plans.log

# OFF again — before the load pass
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "ALTER DATABASE muzbar_bench RESET ALL"'
```

`auto_explain` logs the real execution plan, with timings and real values, of every statement — set
**on `muzbar_bench` only**, so production queries are not logged. Reset before the load pass: plan
timing slows every query a little.

Summary to paste back:

```bash
grep -c '"Plan":' bench/out/plans.log                                                     # statements run
grep -o 'duration: [0-9.]* ms' bench/out/plans.log | sort -k2 -n | tail -5                # 5 slowest
grep -A12 '"Node Type": "Seq Scan"' bench/out/plans.log | grep '"Relation Name"' | sort | uniq -c   # full-table reads
```

A seq scan is not automatically bad (ADR-0026) — it is the evidence we record.

---

## 7b. Optional — B9's planning cost (~30 s; feeds the rate-limit decision)

Locally B9 spent **1.5 s planning** and 18 ms executing; `auto_explain` cannot see planning. This
logs whole-statement times for one B9 request:

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "ALTER DATABASE muzbar_bench SET log_min_duration_statement = 0"'
SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ); sleep 1
U=$(grep -A1 '"B9"' bench/out/scenarios.json | tail -1 | tr -d ' ",')
docker run --rm --network muzbar_bench curlimages/curl:8.11.1 -s -o /dev/null -w '%{time_total}s\n' "http://nginx-bench$U"
sleep 1; docker compose logs --no-log-prefix --since "$SINCE" postgres | grep -o 'duration: [0-9.]* ms  [a-z]*' | awk '{s[$4]+=$2} END {for (k in s) print k, s[k] " ms"}'
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "ALTER DATABASE muzbar_bench RESET ALL"'
```

`bind` is where pdo_pgsql's statements are planned; `execute` is the work.

---

## 8. Load pass, stock pool — **the verdict** (~7 min)

```bash
uptime
$K6 -e BENCH_MODE=load --summary-export /bench/out/load.json /bench/search.js; echo "exit=$?"
uptime
```

50 clients, each firing the next request the moment the last returns, one scenario after another:
10 s warm-up (ignored) + 30 s measured. **`exit=0` = PASS** (every B1–B8 p95 < 200 ms, no failures);
**`exit=99` = FAIL**, ✗ marks the failing scenarios. This is production's real current setup — the
stock PHP-FPM pool of **5 workers**; that number is a finding. If `uptime` went well above 4
*because of other tenants*, repeat the run.

---

## 8b. Load pass, chosen pool — 12 workers (~7 min)

```bash
docker cp docker/php/zz-pool.conf muzbar-app-bench-1:/usr/local/etc/php-fpm.d/zz-pool.conf
$B restart app-bench
docker top muzbar-app-bench-1 | grep -c 'pool www'          # expect 4
uptime
$K6 -e BENCH_MODE=load --summary-export /bench/out/load-pool.json /bench/search.js; echo "exit=$?"
uptime; free -m
```

The pool file goes into `app-bench` only — production's `app` never sees it. Same image, same data:
the pool is the only difference. If p95 moves clearly, the file goes into the Dockerfile (T11a).
Watch `free -m` — no swap.

---

## 9. Clean up (~2 min — don't skip)

```bash
$B rm -sf app-bench nginx-bench
docker compose exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --force muzbar_bench'
docker volume rm muzbar_bench_public
docker network rm muzbar_bench
docker compose exec app php bin/console dbal:run-sql "SELECT datname FROM pg_database"   # muzbar_bench gone
unset MUZBAR_IMAGE
```

Fetch the results, then delete the bench files:

```bash
# laptop:
scp -r <box>:/home/muzbar-deploy/muzbar.com/bench/out ./bench/out-box
# box:
rm -r bench docker-compose.bench.yml docker/php/zz-pool.conf
```

*Why:* 11 500 fake listings off the production server, and a stray database would trip the next
restore rehearsal's count check.

---

## What to bring back

- Step 3 output (`rolsuper`, `nproc`, `free`, `uptime`); the seed's last progress line.
- `service.json`, `load.json`, `load-pool.json` (or the terminal summaries of steps 6, 8, 8b).
- The three lines from step 7 (+ 7b if run); `uptime` around each load pass.

→ results log, ADR-0026 Results, the T11a call (pool into the Dockerfile) and the T11z call
(`/browse` rate limit, Q7).

**Optional, ungated (Q8):** 50 000 listings shows `phase2-gate`'s headroom — after 8b, drop and
re-create `muzbar_bench`, steps 4–5 with `--listings=50000` (~30–45 min), then 6 and 8b only.

**Expectation, written down before measuring:** the chosen pool beats the stock one clearly; B1–B8
likely stay above 200 ms p95 — 4 shared cores cap near ~80 req/s against the ~250 req/s the budget's
reading needs (Little's law). That is ADR-0026 d.5's CPU-bound outcome: the owner's call (box, §7,
or accept), not a Postgres verdict.

---

## Re-run at 10 requests/s (after the 2026-11-25 run — ADR-0026 amendment)

The first run measured 50 clients (FAIL, CPU-bound) and dropped `muzbar_bench`. The new reading
needs **only the load pass**, but on a fresh seed — the service and explain numbers don't change
with the load, so they are not repeated. Pool step 8b is gone (pool not shipped).

1. Branch head has a new `bench/search.js`: **step 1, `search.js` only** (`scp bench/search.js …`).
   No new image — the seeder is unchanged, so `5ec2bb6` is still right.
2. Steps **2–5** (export `MUZBAR_IMAGE=…:5ec2bb64f17c421eb72f1b95cd55ce90b6176bbc`; pre-check
   `uptime` only; create; seed; `ANALYZE`).
3. **Step 8** — the load pass, as written. Expect ~7 min; at 10 req/s the load average should stay
   near 1 until B9 (last, ungated), which overloads the box for ~40 s by design.
   `exit=0` = PASS. In the summary, each `dropped_iterations{scenario:Bn}` must be `count==0`.
4. **Step 9** — clean up, and confirm `muzbar_bench` is gone.

Bring back: the load summary (or `bench/out/load.json`) and the `uptime` lines.
