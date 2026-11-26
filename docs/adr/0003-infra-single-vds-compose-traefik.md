# ADR-0003: Single VDS, Docker Compose, shared Traefik

- **Status:** Accepted
- **Date:** 2026-07-23

## Context

The business must be cheap enough that MRR covers hosting within six months. The owner already runs a
shared **Traefik** reverse proxy on the target VDS, fronting samolit.com (Laravel) with automatic
Let's Encrypt certs, plus an already-running self-hosted **Umami** analytics instance. muzbar should
slot into that existing box and **reuse the shared services**, not stand up duplicates.

## Decision

Deploy muzbar as a **Docker Compose** stack on the **same single VDS**, joining the existing external
`traefik` network. Container topology (mirrors samolit, adapted to Symfony):

| Container | Image | Role |
|---|---|---|
| `nginx` | nginx:1.27-alpine | Reverse proxy → PHP-FPM; Traefik-labelled `Host(muzbar.com)` |
| `app` | custom PHP 8.4-FPM | Symfony application |
| `postgres` | postgres:16-alpine | Database (healthchecked) |
| `redis` | redis:7-alpine | Cache, sessions, Messenger transport (password + alias) |
| `worker` | custom PHP 8.4-FPM | `messenger:consume async` |
| `scheduler` | custom PHP 8.4-FPM | Symfony Scheduler worker (30-day lifecycle) |

muzbar runs **no** analytics container. It reuses the **existing Umami instance already running on the
VDS** — muzbar is registered there as an additional website and includes its tracking snippet in the
public layout. One Umami, many sites. (Same principle applies to any future shared service.)

Two Compose files, following samolit's proven split:

- `docker-compose.yml` — **production** build target, Traefik labels, no exposed datastore ports.
- `docker-compose.dev.yml` — **explicitly passed, never auto-loaded**; switches to the `development`
  build target, mounts source live, adds Xdebug + Mailpit, and binds ports to `127.0.0.1` only.

## Alternatives

- **A separate VDS / managed PaaS:** more cost, defeats the self-sufficiency goal.
- **Kubernetes / Swarm:** absurd overkill for one app run by one person. Explicit non-goal.
- **Managed Postgres/Redis:** recurring cost; the box is small and backed up via `pg_dump`.

## Consequences

- **Easy:** near-zero marginal hosting cost, one place to operate, TLS handled by existing Traefik.
- **Hard / watched — the documented footguns become checklist items** (see infrastructure.md):
  - Pin Traefik to its network (`--providers.docker.network=traefik`) or hit the silent 30 s 504.
  - Docker writes iptables directly and **bypasses UFW** — a `ports:` mapping is a public hole.
    Datastores expose no ports in prod; admin UIs bind to `127.0.0.1` and are reached over SSH.
  - **Named volumes only.** Anonymous Postgres volumes remember the old `POSTGRES_PASSWORD`, and
    `docker compose down -v` does not remove them.
  - Redis gets a password and a network alias (a bare `redis` hostname can resolve to redis.com).
- Shared box means noisy-neighbour risk with samolit; watch memory (PHP-FPM `max_children`, Doctrine
  hydration) — the owner's wiki documents both.

## Amendment, 2026-11-26 — the Umami snippet lands, and where it never renders

Recorded by the `public-styles` slice, on the owner's answers Q2–Q4 (2026-11-26). The decision above
— reuse the shared Umami on the box, no analytics container — stands.

1. **Config shape: two optional env vars, empty = off.** `UMAMI_SCRIPT_URL` (the shared instance's
   `script.js`) and `UMAMI_WEBSITE_ID` (muzbar's site UUID), read as Twig globals through
   `%env(default::…)%` so neither is a boot-time dependency. The snippet renders only when **both** are
   set: `<script defer src="…" data-website-id="…" data-exclude-search="true">`. Runbook:
   docs/infrastructure.md §*Analytics (Umami)*.
2. **Where it renders:** every page on the public layout (`base.html.twig`'s `analytics` block) and the
   coming-soon page `/` (Q2 — the only page with real traffic today).
   **Where it never renders:** admin (the admin layout empties the block), mail templates, and the
   token URLs `/verify-email/{token}` and `/reset-password/{token}` — both only redirect, and
   `AnalyticsSnippetTest` pins that. A future page under `base` that shows a token or secret in its
   URL (or a custom error page) must empty the `analytics` block.
3. **Query strings are excluded** (`data-exclude-search`, Q3): `/browse`'s `q` is free text a visitor
   typed. Facet analytics wait for `search-ui`, and need this re-opened on purpose.
4. **No consent banner** (Q4). Umami is self-hosted (no third party receives data), sets no cookies and
   no local storage, and stores no IP address. muzbar adds no custom events with user data. **Any of
   these re-opens the question:** custom events carrying user data, a second tracker, cookies, or
   moving Umami off the box. A privacy-policy page mentioning Umami is owed before public launch
   (roadmap, cross-cutting).
