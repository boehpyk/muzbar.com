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

- **The `worker` box is real as of 2026-07-28** (`messenger-worker`, added by
  `identity-email-verification`); the `scheduler` box is still a plan. The worker runs the same image
  as `app` with a different command, publishes no ports and mounts no assets volume — see
  *Outgoing mail & the queue* below, and note footgun #7: **nothing currently alerts when it stops.**
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
| 7 | **Messenger worker stopped** (crashed, never restarted, wrong transport name) | mail simply never arrives; **every automated check stays green**, because `/health/ready` probes Postgres and Redis, not queue depth | `restart: unless-stopped` + `--time-limit=3600`; check `messenger_messages` depth and `messenger:failed:show` in the runbook below. Real fix (Phase 2): teach readiness about queue age |
| 8 | Two dev containers sharing one live-mounted `var/cache/` | `require(var/cache/dev/ContainerXXXX/getSomeService.php): Failed to open stream` — a 500 on **every** route including the error page, from correct code with a green test suite | `docker-compose.dev.yml` gives `messenger-worker` an anonymous volume over `var/cache`. Prod is unaffected: no bind mount, so each container has its own `var/` |

A **pre-commit hook** greps compose files for public `ports:` on `postgres`/`redis` and for a missing
Traefik network pin, and blocks the commit (see [tooling.md](./tooling.md)).

## Health checks

- `GET /health/live` → process is up (no dependency calls). For Docker/Traefik liveness.
- `GET /health/ready` → actively probes Postgres (`SELECT 1`) and Redis (`PING`); returns per-check
  status and a non-200 if any dependency is down. Never a bare `return 'ok'`.
- **What readiness does NOT cover, stated so nobody is surprised by it:** the `messenger-worker`
  container. A worker that crashed an hour ago leaves `/health/ready` at 200, Traefik happy and
  every uptime ping green, while no verification mail reaches anybody. Until readiness learns about
  queue age (Phase 2), **the worker is monitored by the runbook below and by nothing else.**

## Outgoing mail & the queue (runbook)

Mail is asynchronous ([ADR-0010](./adr/0010-event-delivery-and-transactional-mail.md)): the app
writes a `SendEmailMessage` into `messenger_messages` on `doctrine://default` and the
**`messenger-worker`** service drains it. Nothing is delivered while that container is down.

```bash
docker compose ps messenger-worker                        # is it even up?
docker compose logs messenger-worker --tail=50            # it exits hourly by design (--time-limit)
make console cmd="messenger:failed:show"                  # exhausted retries land here
make console cmd="messenger:failed:retry"                 # after fixing the cause
# queue depth — a number that only ever grows means the worker is not consuming
docker compose exec -T postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -c "select queue_name, count(*) from messenger_messages group by queue_name;"
```

Two things to know before debugging:

- **The worker exits on purpose, once an hour.** `--time-limit=3600` plus `restart: unless-stopped`
  is the supervisor-free way to bound a slow memory leak. A log ending in a clean shutdown is
  healthy; a container in a *restart loop* is not.
- **`messenger_messages` is created by a migration, not by Messenger.** `MESSENGER_TRANSPORT_DSN`
  carries `auto_setup=0` in every environment, so a missing table is a missed `make migrate` and
  will not be papered over at runtime — which is deliberate, since auto-setup is a schema change
  nobody reviewed, applied by a worker process at an arbitrary moment.

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
- **Sentry** (or equivalent) for error tracking — **overdue as of 2026-07-28**, and the case is now
  stronger than "cheap insurance". `identity-email-verification` introduced two paths that fail
  *silently by design*: `IssueVerificationOnUserRegistered` swallows its own exceptions so a dead
  mail relay cannot 500 an already-committed registration, and the Messenger failure transport is a
  queue nobody opens. Both are the correct designs. Both write a log line that nothing reads.
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

Provisioning the least-privilege access that deploy relies on — the repo-scoped GitHub deploy key and
the caged `muzbar-deploy` VDS user (scoped to `/home/muzbar-deploy/muzbar.com`, on the shared system
Docker + Traefik) — is a step-by-step runbook in [access-setup.md](./access-setup.md).
