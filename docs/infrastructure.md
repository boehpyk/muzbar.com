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

Three things to know before debugging:

- ⚠ **`deploy.yml` never restarts `messenger-worker`, so it runs whatever image it last had.** The
  deploy does `docker compose pull app` and `docker compose up -d app nginx` — the worker is in
  neither list. It is therefore serving code from some earlier deploy, indefinitely, and the only
  symptom is behaviour that does not match the source. Found on 2026-08-01 while costing
  `identity-challenge-pruning`'s scheduling decision; it is a **pre-existing `devops` bug, not
  something that slice introduced**, and it is the single strongest argument against adding a second
  long-running container. Until it is fixed, restart the worker by hand after any deploy that changes
  a mail template, a listener or anything else it executes:
  `docker compose pull messenger-worker && docker compose up -d messenger-worker`.
- **The worker exits on purpose, once an hour.** `--time-limit=3600` plus `restart: unless-stopped`
  is the supervisor-free way to bound a slow memory leak. A log ending in a clean shutdown is
  healthy; a container in a *restart loop* is not.
- **`messenger_messages` is created by a migration, not by Messenger.** `MESSENGER_TRANSPORT_DSN`
  carries `auto_setup=0` in every environment, so a missing table is a missed `make migrate` and
  will not be papered over at runtime — which is deliberate, since auto-setup is a schema change
  nobody reviewed, applied by a worker process at an arbitrary moment.

## Challenge pruning (runbook)

Both `Identity` challenge tables grow forever unless something deletes from them.
`muzbar:identity:prune-challenges` is that something, driven by **host cron** — not Symfony
Scheduler ([ADR-0012](./adr/0012-challenge-retention-and-recurring-background-work.md) decision 5;
Constitution §3's Scheduler row is read as scoped by its own `(30-day ad lifecycle)` parenthetical).

**The crontab line. It lives outside git, which is this design's one real cost, so it lives here:**

```cron
17 * * * * cd /home/muzbar-deploy/muzbar.com && docker compose exec -T app php bin/console muzbar:identity:prune-challenges
```

Hourly at minute **17** — off the hour so it never coincides with every other cron on the box.
Hourly rather than daily because it keeps the backlog small enough that a stalled job is detectable
within hours. It targets the **`app`** container deliberately: that is the one `deploy.yml` actually
updates (see the `messenger-worker` warning below), so the command can never run an image older than
the code that was deployed.

### First run — rehearse it, do not discover it

The first real run is the only one that can meet an unbounded backlog. Do this **before** adding the
cron line, and paste both outputs into the verification notes:

```bash
# 1. Report only. Deletes nothing, writes no heartbeat. Read the "Overdue before" column.
make console cmd="muzbar:identity:prune-challenges --dry-run"

# 2. A small, explicit bite. Confirm the row counts move by exactly what you asked for.
make console cmd="muzbar:identity:prune-challenges --limit=100"

# 3. Full runs until "Overdue before" reaches 0. A capped run reports truncated and exits 0;
#    just run it again.
make console cmd="muzbar:identity:prune-challenges"

# 4. Only now, add the crontab line.
```

Exit codes: **0** on success, on a truncated run and on a lock-skip; **1** only on a genuine failure
(Postgres unreachable), logged at `error`.

### What to check when the backlog grows

The backlog — `jobs.challenge_pruning.overdue_verification` / `overdue_reset` in `/health/ready`'s
body — is the **primary** signal, and the only one that cannot be faked by a job that runs and does
nothing. It is ~0 in a healthy system and grows monotonically when nothing runs.

```bash
curl -s localhost:8080/health/ready | jq .jobs.challenge_pruning
#   overdue_* climbing        -> nothing is sweeping. Work down the list below.
#   stale: true               -> no heartbeat for >3 h (three missed runs).
#   last_run: null            -> the job has never run, or Redis was flushed.

crontab -l | grep prune-challenges          # is the line still there? it is not in git.
grep CRON /var/log/syslog | tail            # did cron fire and fail? exec failures land here.
docker compose ps app                       # `exec` fails if the container is down.
docker compose logs app --tail=100 | grep challenge_pruning
redis-cli -a "$REDIS_PASSWORD" GET identity:challenge_pruning:lock   # a stuck lock? it has a 1 h TTL.
make console cmd="muzbar:identity:prune-challenges --dry-run"        # what does it think it would do?
```

Note the two states that look alike and are not: `stale: true` with `overdue_*` at **0** means the
heartbeat is missing (very likely Redis was flushed) while the sweeping is fine; `stale: false` with
`overdue_*` **climbing** should be impossible and means the job is running and failing to delete.

**`/health/ready` never 503s over any of this** and must not be changed to. Readiness answers "should
traffic come here"; a housekeeping job that stopped is not a reason to take the site out of rotation,
and a probe that 503'd over one would have Docker restart a perfectly healthy container in a loop.

### Retention windows, and the backstop they do not have

| Table | Link lifetime | Retention after expiry |
|---|---|---|
| `identity_email_verification_request` | 24 h | **7 days** |
| `identity_password_reset_request` | 1 h | **30 days** |

**The ordering is inverted on purpose** — the longer-lived link keeps its rows for the shorter time.
Retention measures the question a row still answers *after* it stops working, which has nothing to do
with how long it worked: a verification row answers a days-long support question on the
higher-volume table, a reset row answers an incident-review question whose horizon is how long a
seller might go without logging in. Both windows are Domain constants, so changing one is a deploy.

⚠ **These windows and the database-dump schedule must be decided together.** A dump is the *only*
backstop behind a window that turns out too short — once a row is swept, nothing else remembers which
challenge a password was reset from. `make db.dump` is currently **on-demand and unscheduled** (see
*Backups & recovery*, where a daily dump is still a to-do), so today there is no backstop at all.
That is an accepted, recorded risk rather than an oversight.

### GDPR erasure — specified here, built nowhere

There is no user-deletion path today, so there are currently **zero** orphan rows. When erasure is
built, two rules are not negotiable and are written here because this is where the future slice will
look:

1. **Delete the person's rows from both challenge tables *before* the `identity_user` row.** A crash
   mid-way then leaves a user with no challenges — tidy, and indistinguishable from a user who never
   requested one. The reverse order leaves orphaned challenge rows that read as corruption to
   everyone who finds them afterwards, including the integrity probe.
2. **Retention windows do not apply to an erasure request.** Erasure is immediate and complete. A
   design that says "the pruner will get to it within thirty days" has not implemented erasure; it
   has scheduled one.

Erasure belongs behind a `deleteForUser()` method on each repository port, added by the slice that
has a caller and a decision behind it. The pruning job must not acquire one, and
`ChallengeIntegrityProbe` must never acquire a delete method at all — a probe that can delete is one
refactor away from being a second, undocumented retention policy.

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
  **This is now coupled to a data-retention decision and is no longer only an availability concern.**
  As of `identity-challenge-pruning` the system deletes rows on a schedule (7 days / 30 days after
  expiry), and a dump is the only thing standing behind a window that turns out too short. Decide the
  dump schedule and those windows together — see *Challenge pruning (runbook)*.
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
