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
