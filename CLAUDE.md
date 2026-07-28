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

> Status: **Phase 0 complete** (2026-07-23 → 07-24) · **Phase 1 slices 1–2 of 5 complete**.
> `identity-user-password-auth` (2026-07-26) shipped the repo's first Domain code — the `User`
> aggregate, value objects, ports, two domain events, the first migration, and
> register/login/logout/`/account`. `identity-email-verification` (2026-07-28) added the **second
> aggregate**, `EmailVerificationRequest`, the first cross-aggregate reference by identity, async
> mail over Messenger, and the `VerifiedAccountUserChecker` that finally enforces `User::isUsable()`
> at login — without touching `User` at all.
> Current work: the remaining `Identity` slices, starting with `identity-password-reset`.
> Two Phase 0 items still carry over — the Claude Code hooks from
> [docs/tooling.md](./docs/tooling.md), and **Sentry, now overdue**: slice 2 added an asynchronous
> failure path (a swallowed listener exception, a failure transport nobody reads) that is silent by
> construction. See [docs/roadmap.md](./docs/roadmap.md).

## Architecture — non-negotiable

DDD + Hexagonal (Ports & Adapters). App code lives under `src/src/` (Symfony root is `src/`) in three
layers with strictly ordered dependencies:

| Layer | May import | Must NOT import |
|---|---|---|
| `Domain` | nothing external | Symfony, Doctrine, any framework/vendor |
| `Application` | `Domain` | `Infrastructure`, Doctrine, controllers |
| `Infrastructure` | `Domain` + `Application` + Symfony/Doctrine | — |

Enforced by **Deptrac** in CI — via a catch-all `Vendor` layer (anything not under `App\`, minus real
PHP/SPL built-ins) granted only to `Infrastructure`, so *every* vendor is caught rather than a list of
named ones, plus the `use` emitter so a bare unused import counts. Repository/`*Port` interfaces live in `Domain/<Context>/Port/`;
adapters live in `Infrastructure/`. Bounded contexts: `Catalog`, `Listing`, `Directory`, `Identity`,
`Billing`, `Notification`, `Search` — see Constitution §4.

Canonical order to add a feature: **Domain** (entity/aggregate, value objects, domain events, port) →
**Application** (command/query + handler) → **Infrastructure** (Doctrine adapter, controller/Live
Component, DI wiring).

**Persistence conventions** ([ADR-0007](./docs/adr/0007-persistence-conventions-for-domain-aggregates.md)) —
established by the first slice, inherited by every context:

- Doctrine mapping is **XML, one file per aggregate**, at
  `src/Infrastructure/<Context>/Persistence/Doctrine/mapping/<Aggregate>.orm.xml`, with one
  `doctrine.orm.mappings` block per context. **Never ORM attributes on a Domain class** — that is a
  `use Doctrine\...` in `Domain/` and Deptrac fails the build. There is no `src/Entity/`.
- Value objects map through **custom DBAL types** (`Infrastructure/<Context>/Persistence/Doctrine/Type/`,
  registered under `doctrine.dbal.types` as `<context>_<concept>`), not embeddables.
- **Tables are `<context>_<aggregate>`, singular** (`identity_user`), with every table and column name
  spelled out explicitly in the XML rather than derived by the naming strategy.
- **Column types are chosen, not inherited from driver defaults.** JSON columns are **`jsonb`** — extend
  `Doctrine\DBAL\Types\JsonbType`, never `JsonType` (whose PostgreSQL default is textual `json`) and
  never the deprecated `jsonb` column option. Timestamps are **whole-second**: `datetimetz_immutable`
  discards microseconds at the *type*, so `Clock` mandates whole seconds and `SystemClock` truncates at
  the source. Any hand-written `Clock` test double must honour that too.
- Identity is **application-assigned UUIDv7** minted by `Repository::nextIdentity()`, mapped with
  `<generator strategy="NONE"/>`. Adapters implement the port and do **not** extend
  `ServiceEntityRepository`.

**References between aggregates** ([ADR-0009](./docs/adr/0009-email-verification-tokens-modelled-in-the-domain.md)) —
established by the second slice, inherited by every context:

- An aggregate holds another's **id value object, never the object** (`UserId`, not `User`). The
  mapping is a plain typed column, **never a `<many-to-one>`**: an association hands anyone holding
  one root the power to mutate another, and lets Doctrine's cascades decide transactional semantics.
- **No database foreign key between aggregates.** Referential integrity across roots is the
  application's job, and an FK the mapping does not know about is diffed as unwanted on every
  `make migration.make` — deleted and re-added forever. The cost is possible orphan rows; the
  pruning job and the GDPR-erasure design own that.
- Two aggregates changing in one use case means **two saves in a deliberate order**. Pick the order
  whose crash window is benign, and write down why at the call site.

**Domain events** ([ADR-0008](./docs/adr/0008-domain-events-recorded-on-the-aggregate.md)): the
aggregate `recordThat()`s via the `RecordsEvents` trait and never dispatches; the Application handler
calls `$this->events->dispatch(...$aggregate->releaseEvents())` **after** a successful `save()`.
`releaseEvents()` empties the buffer. Events carry value objects, never the aggregate — and **never a
secret**: a token inside an event is a token in every listener, every log line and every queue row.

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
make deptrac                   # layer boundary check (--fail-on-uncovered: an unclassified
                               #   dependency fails the build instead of being silently allowed)
make test.db                   # create + migrate muzbar_test (run automatically by `make test`)
make test                      # phpunit  (opts: filter=, file=)
make check                     # cs + stan + deptrac + test — run before every commit

# Identity operations
make console cmd="muzbar:identity:verify-email <email>"   # break-glass: mark an email verified,
                                                          #   bypassing the token flow entirely
# Mail & queue (dev)
open http://localhost:8025                                # Mailpit — every dev mail lands here
make console cmd="messenger:failed:show"                  # the failure transport nobody looks at
make console cmd="messenger:failed:retry"
```

**Async mail** ([ADR-0010](./docs/adr/0010-event-delivery-and-transactional-mail.md)): `SendEmailMessage`
is routed to a Doctrine-backed `async` transport drained by the **`messenger-worker`** Compose
service. Nothing is delivered while that container is down — and **a stopped worker looks exactly
like a healthy system**, because `/health/ready` probes Postgres and Redis, not queue depth. Under
`APP_ENV=test` the same message is routed to `sync` so `MailerAssertionsTrait` works without a
worker.

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
  assertions that cannot fail. Build aggregates via a Foundry factory that goes through the aggregate's
  own named constructor (`instantiateWith()`), never around it.
  **DAMA rolls back Postgres, not Redis** — anything cached survives between tests and must be
  cleared in `setUp()` via the `ClearsRateLimiters` trait. The `cache.rate_limiter` pool now backs
  **two** limiters (`login_throttling` and `verification_email_resend`), so a test driving either
  `/login` or `/verify-email/resend` needs it.
- **Swapping an adapter inside a test needs two things, and each fails silently alone.** Target the
  **concrete class's** service id, never the port alias — Symfony's `ResolveReferencesToAliasesPass`
  rewrites alias references to their target at *compile* time, so a handler's constructor is already
  wired to the adapter's id before the test runs. And call `$client->disableReboot()` first, because
  `KernelBrowser` reboots the kernel before every request and discards container overrides.

## Infrastructure footguns (baked-in guards)

These are documented failure modes we design against (see [docs/infrastructure.md](./docs/infrastructure.md)):

- Traefik must be pinned to its network: `--providers.docker.network=traefik`.
- Never expose Redis/Postgres with a public `ports:` mapping — Docker bypasses UFW. Bind admin/data
  ports to `127.0.0.1` only. A pre-commit hook flags violations.
- Use **named** volumes only; anonymous Postgres volumes remember stale passwords.
- Give Redis a password and a stable network alias.
- Health checks probe Postgres + Redis — never `return 'ok'`.
- **`env_file:` outranks the image's `ENV`, so the root `.env` decides `APP_ENV` on a real box** — the
  Dockerfile's `ENV APP_ENV=prod` cannot protect you. `.env.example` therefore defaults to
  `APP_ENV=prod` (the safe value), and `docker-compose.dev.yml` pins `APP_ENV: dev` in `environment:`
  (which outranks `env_file:`) so dev-ness follows the override file you load rather than a value
  someone remembered to change. Verified empirically, not assumed.
- The container writes into the live-mounted `./src` **as root**, so directories it creates (e.g.
  `src/translations/`) become un-manageable by host git and can block a `git pull`. Chown to `1000:1000`
  when it happens; a `user:` mapping in the dev override would fix it at the source.
- **Two dev containers sharing one live-mounted `var/cache/` corrupts it.** `app` and
  `messenger-worker` both bind `./src`, and Symfony warms the cache into a hash-named directory
  before swapping it in — so two processes warming concurrently (which is what happens the first
  time each serves a request after a code change) can leave one holding a container hash whose
  service files the other already replaced. It presents as
  `require(var/cache/dev/ContainerXXXX/getSomeService.php): Failed to open stream` on **every**
  route including the error controller, from an application that is correct and a suite that is
  green. `docker-compose.dev.yml` gives the worker an anonymous volume over `var/cache`. Prod is
  unaffected — no bind mount, so each container already has its own `var/`.
- **`DEFAULT_URI` decides every absolute URL built outside an HTTP request** — the Messenger worker
  and every console command. Left at the skeleton's `http://localhost`, every link in every outgoing
  mail points somewhere unreachable, and **no functional test catches it**, because a test runs
  inside a simulated request where the fallback never fires. Set it per environment.

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
