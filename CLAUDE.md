# CLAUDE.md

Guidance for Claude Code working in this repository. Read the [Constitution](./docs/constitution.md)
before planning any feature — it is the source of truth for stack, architecture, and quality gates.

## Project

**muzbar.com** — a hyper-granular musical-instrument marketplace + local music-services directory.
Structured faceted search over structured data, on a single VDS, run by one person. Full product
intent: [docs/muzbar-PRD.md](./docs/muzbar-PRD.md).

Secondary goal (ranked, not incidental): **learn DDD + Symfony deeply.** Prefer the implementation
that teaches DDD honestly.

**Stack:** PHP 8.4 · Symfony 7 · Twig + Symfony UX (Live Components) · AssetMapper (Node-free) ·
Tailwind (admin UI) + hand-authored SCSS (public UI) · Doctrine · PostgreSQL 16 · Redis 7 ·
Docker Compose · Traefik · Nginx.

> Status: **Phase 0 complete** (2026-07-23 → 07-24). The Symfony app, Docker stack, quality gates,
> CI/CD, and the `/health/*` endpoints are live and deployed — the commands below **work today**.
> Current work: **Phase 1 — `Identity` context** (sliced into five cycles in the roadmap).
> `Domain/Shared/` and `Application/Shared/` are still empty: the first slice writes this repo's first
> domain code. Two Phase 0 items carry over — the Claude Code hooks from
> [docs/tooling.md](./docs/tooling.md), and Sentry (deferred by decision to the first Identity slice).
> See [docs/roadmap.md](./docs/roadmap.md).

## Architecture — non-negotiable

DDD + Hexagonal (Ports & Adapters). App code lives under `src/src/` (Symfony root is `src/`) in three
layers with strictly ordered dependencies:

| Layer | May import | Must NOT import |
|---|---|---|
| `Domain` | nothing external | Symfony, Doctrine, any framework/vendor |
| `Application` | `Domain` | `Infrastructure`, Doctrine, controllers |
| `Infrastructure` | `Domain` + `Application` + Symfony/Doctrine | — |

Enforced by **Deptrac** in CI. Repository/`*Port` interfaces live in `Domain/<Context>/Port/`;
adapters live in `Infrastructure/`. Bounded contexts: `Catalog`, `Listing`, `Directory`, `Identity`,
`Billing`, `Notification`, `Search` — see Constitution §4.

Canonical order to add a feature: **Domain** (entity/aggregate, value objects, domain events, port) →
**Application** (command/query + handler) → **Infrastructure** (Doctrine adapter, controller/Live
Component, DI wiring).

## Commands

All application commands run inside the Docker `app` container. (Makefile targets wrap these — run
`make help`.)

```bash
# Start the full stack (dev: Xdebug, live mount, Mailpit, 127.0.0.1-bound ports)
make up.dev            # docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Production mode (no override file — never auto-loaded)
make up.prod

# Symfony console
make console cmd="..."         # docker compose exec app php bin/console ...

# Doctrine
make migrate                   # doctrine:migrations:migrate
make migration.make            # make:migration
make db.dump                   # pg_dump -Fc to backups/

# Quality gates (also run in CI)
make cs                        # php-cs-fixer
make stan                      # phpstan max
make deptrac                   # layer boundary check
make test.db                   # create + migrate muzbar_test (run automatically by `make test`)
make test                      # phpunit  (opts: filter=, file=)
make check                     # cs + stan + deptrac + test — run before every commit
```

## Conventions

- **PHP:** constructor property promotion; explicit return types; typed params everywhere; enums with
  TitleCase cases; curly braces on all control structures; no dead code; no secrets in source.
- **Domain purity:** zero `use Symfony\...` / `use Doctrine\...` in `Domain/`. Doctrine mapping is an
  infrastructure concern. Model the business, not the table.
- **Config:** never read env vars outside `config/` / DI parameters; inject configured values.
- **Validation:** untrusted input is validated at the Infrastructure boundary before reaching the
  Domain. The search-query sanitizer is security-critical.
- **Tests:** Domain gets pure unit tests (no kernel boot); Application/Infrastructure get integration
  tests against a real database (DAMA transactional rollback). Tests run with `APP_ENV=test` against a
  **dedicated test database** (`muzbar_test`) — **never** the dev DB. Descriptive test names. No
  assertions that cannot fail.

## Infrastructure footguns (baked-in guards)

These are documented failure modes we design against (see [docs/infrastructure.md](./docs/infrastructure.md)):

- Traefik must be pinned to its network: `--providers.docker.network=traefik`.
- Never expose Redis/Postgres with a public `ports:` mapping — Docker bypasses UFW. Bind admin/data
  ports to `127.0.0.1` only. A pre-commit hook flags violations.
- Use **named** volumes only; anonymous Postgres volumes remember stale passwords.
- Give Redis a password and a stable network alias.
- Health checks probe Postgres + Redis — never `return 'ok'`.

## SDLC

Lean solo Spec-Driven Development. Loop: **`/plan` → `/implement` → `/verify`.** Every feature gets a
short spec in `docs/specs/<feature>/`. Details: [docs/sdlc.md](./docs/sdlc.md). Agents/commands:
[docs/tooling.md](./docs/tooling.md).

**Branching:** GitHub Flow — `feature/<name>` (or `fix/<name>`) → PR → squash-merge to protected
`main` → build image → **manual-gated** deploy. Never `git pull` on prod. See [docs/cicd.md](./docs/cicd.md).

## Documentation duties

When behaviour changes, update: this file (if a convention/command changed), the relevant ADR (if a
decision changed), and [FORboehpyk.md](./FORboehpyk.md) (the running plain-language project story —
capture bugs hit and lessons, per the owner's standing rule).
