# Muzbar Infrastructure Plan

Target: **one existing VDS**, Docker Compose, sharing the box (and the Traefik proxy) with
samolit.com. Decisions in [ADR-0003](./adr/0003-infra-single-vds-compose-traefik.md). This doc is the
operational blueprint + the footgun checklist that turns the owner's hard-won war stories into guard
rails.

## Topology

```
                      Internet :443
                          │
                   ┌──────▼───────┐   (shared, external docker network: `traefik`)
                   │   Traefik    │   Let's Encrypt (certresolver=le)
                   │  (existing)  │   --providers.docker.network=traefik   ← pinned!
                   └──────┬───────┘
                muzbar.com│         ┌ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┐
                  ┌────▼───┐          existing shared Umami instance (own
                  │ nginx  │        │ compose stack; muzbar sends tracking  │
                  └───┬────┘          events to it — NOT part of this stack)
                      │ fastcgi     └ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ┘
                 ┌────▼─────┐
                 │   app    │             default network (internal only — NO published ports in prod)
                 │ PHP-FPM  │
                 └─┬───┬──┬─┘
        ┌──────────┘   │  └───────────┐
   ┌────▼────┐   ┌─────▼────┐   ┌─────▼────┐   ┌──────────┐   ┌───────────┐
   │postgres │   │  redis   │   │  worker  │   │scheduler │   │  (mailpit │
   │ :16     │   │  :7      │   │messenger │   │Scheduler │   │  dev only)│
   └─────────┘   └──────────┘   └──────────┘   └──────────┘   └───────────┘
```

- **Analytics is not in this stack.** muzbar reuses the Umami container already running on the VDS
  (its own compose stack); the public layout just embeds muzbar's tracking snippet. Nothing to deploy.
- **Prod ports:** only `nginx` joins the `traefik` network. **Postgres, Redis, app expose no host
  ports.** They talk over the internal `default` network by service name.
- **Dev ports:** `docker-compose.dev.yml` binds `postgres`, `mailpit`, `nginx` to `127.0.0.1` only —
  reachable from the host / over SSH, never from the internet.

## Compose file strategy (from samolit, proven)

- `docker-compose.yml` — production. `build target: production`, Traefik labels, healthchecks,
  `restart: unless-stopped`, **no datastore `ports:`**.
- `docker-compose.dev.yml` — **explicitly passed, never auto-loaded** (`-f docker-compose.yml -f
  docker-compose.dev.yml`). Switches to `development` target, live-mounts source, adds Xdebug +
  Mailpit, binds ports to `127.0.0.1`. Not auto-loading it is the safety mechanism that prevents
  running dev config (open ports, debug) on prod.

## The footgun checklist (design against these — they have all bitten before)

| # | Failure | Symptom | Guard |
|---|---|---|---|
| 1 | Traefik picks wrong Docker network IP | TLS ok, then silence, **504 at exactly 30 s**, no logs | Pin `--providers.docker.network=traefik`; keep nginx on both `default` + `traefik` |
| 2 | Docker bypasses UFW (writes iptables directly) | a `ports:` mapping is a public hole even with UFW "deny" | No datastore ports in prod; admin/data bind `127.0.0.1`; reach via SSH tunnel |
| 3 | Stale **anonymous** Postgres volume | password auth fails after changing `POSTGRES_PASSWORD` | **Named volumes only** (`postgres_data:`); note `down -v` won't drop anonymous volumes |
| 4 | Bare `redis` hostname resolves to redis.com | intermittent cache failures / wrong host | Redis **password** + explicit Docker **network alias** |
| 5 | Health check returns 200 because PHP runs | outages hidden behind a green check | Separate **liveness** (process up) from **readiness** (probe Postgres + Redis) |
| 6 | PHP-FPM `max_children` / Doctrine hydration OOM | worker/app OOM under load on the shared box | Cap FPM children; partial hydration / batching in Doctrine; `--memory` on Messenger consume |

A **pre-commit hook** greps compose files for public `ports:` on `postgres`/`redis` and for a missing
Traefik network pin, and blocks the commit (see [tooling.md](./tooling.md)).

## Health checks

- `GET /health/live` → process is up (no dependency calls). For Docker/Traefik liveness.
- `GET /health/ready` → actively probes Postgres (`SELECT 1`) and Redis (`PING`); returns per-check
  status and a non-200 if any dependency is down. Never a bare `return 'ok'`.

## Secrets & configuration

- `.env` is **not** committed; `.env.example` is. Prod secrets live in `.env` on the box (or Docker
  secrets), never in the image or git.
- Symfony reads config via parameters/DI, never `getenv()` scattered in code.
- muzbar's Postgres serves the app only — no extra databases to provision (Umami has its own on its
  own stack). The public layout needs the shared Umami site ID / script URL as config.

## Backups & recovery

- **`make db.dump`** → custom-format `pg_dump -Fc --no-owner --no-privileges` into `backups/`
  (portable, parallel-restorable), same pattern as samolit.
- Schedule a daily dump (cron on the host or a Scheduler task) with off-box copy (rsync/object store).
- **Rehearse restore** at least once before launch and after any schema-mutation milestone — an
  untested backup is a rumour, not a backup.

## Monitoring & observability

- **Umami** for product analytics — the **shared instance already running on the VDS**, not a muzbar
  container. Register muzbar as a website in it and embed the tracking snippet in the public layout.
  Directly feeds the PRD's Form-Completion-Rate and Time-to-Publish metrics and the MRR-vs-cost goal.
- **Sentry** (or equivalent) for error tracking — add in Phase 0/1; cheap insurance for a solo dev.
- Structured application logs to `storage/logs`, mounted out of the container.
- Uptime ping against `/health/ready`.

## Environments

- **prod:** `main` deployed to the VDS.
- **staging on one box:** a lightweight option is a `staging.muzbar.com` Traefik route running the
  same images with a separate database/volume set — decide in Phase 0. Until then, the dev stack on
  the host is the pre-prod check, and CI is the real gate.
- **test:** `APP_ENV=test` against a **dedicated database** (e.g. `muzbar_test`), **never** the dev
  database. **Invariant: tests never touch the dev or prod DB.** Same Postgres container as dev, a
  separate database — Doctrine appends a `_test` suffix in the test env so mis-pointing is structurally
  impossible. `dama/doctrine-test-bundle` wraps each test in a transaction that rolls back at teardown,
  so no data persists. Env in `.env.test` (committed) + `.env.test.local` (gitignored). `make test`
  targets this; **CI** uses a throwaway Postgres service, so it is isolated by construction. Phase 0
  provisions this DB (`doctrine:database:create --env=test` + `migrate --env=test`).

## Deploy

See [cicd.md](./cicd.md). Shape: build image in CI → push to registry (GHCR) → SSH to the box → pull
+ `docker compose up -d` + run migrations. Zero-downtime is not a launch requirement; a few seconds of
restart is acceptable for a solo marketplace at this stage.
