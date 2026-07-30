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

> Status: **Phase 0 complete** (2026-07-23 → 07-24) · **Phase 1 slices 1–3 of 5 complete**.
> `identity-user-password-auth` (2026-07-26) shipped the repo's first Domain code — the `User`
> aggregate, value objects, ports, two domain events, the first migration, and
> register/login/logout/`/account`. `identity-email-verification` (2026-07-28) added the **second
> aggregate**, `EmailVerificationRequest`, the first cross-aggregate reference by identity, async
> mail over Messenger, and the `VerifiedAccountUserChecker` that finally enforces `User::isUsable()`
> at login — without touching `User` at all.
> Slice 2 passed `/verify` on 2026-07-28 after three fix iterations; it also configured
> `framework.trusted_proxies`, which retroactively makes slice 1's `login_throttling` limiter work.
> `identity-password-reset` (2026-07-29) added the **third aggregate**, `PasswordResetRequest` —
> the same *shape* as slice 2's with **four rules deliberately inverted** (a replay is refused, a
> reissue invalidates outstanding links, the GET mutates nothing, and the two saves go in the
> opposite order). Established [ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md).
> It is the first slice to touch `User` since slice 1, by exactly one property, one method, one
> reader and one import — and the first where a successful use case in one context (reset) also
> discharges a fact owned by another use case (email verification).
> Current work: the remaining `Identity` slices — next is `identity-challenge-pruning`, which now
> has **two** challenge tables to sweep.
> Two Phase 0 items still carry over — the Claude Code hooks from
> [docs/tooling.md](./docs/tooling.md), and **Sentry, no longer merely overdue**: slice 2 added
> asynchronous failure paths that are silent by construction (a swallowed listener exception, a
> failure transport nobody reads), and its verification pass then spent an entire session with
> `messenger-worker` crash-looping while `make check` and `/health/ready` both reported green. The
> gap is demonstrated, not theoretical. See [docs/roadmap.md](./docs/roadmap.md).

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
  whose crash window is benign, and write down why at the call site. **This rule has now produced two
  opposite answers, which is the evidence that it is a rule and not a habit**: verification saves
  user-first (a surviving token is inert once the account is verified), reset saves *request-first*
  (a surviving token is another chance to set a password —
  [ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md) decision 5). Both
  call sites name the other one, so a reader who spots the contradiction finds the reason instead of
  "aligning" them.

**Structurally identical aggregates still do not share a base class.** `PasswordResetRequest` is ~80%
the same file as `EmailVerificationRequest` and shares no supertype with it. They share a *shape*,
not *behaviour*, and they differ on **four** rules — replay refused vs absorbed, reissue invalidating
vs not, the GET mutating vs burning, and the save order. Any guess a base class made on those would
be right for one subclass and a latent security bug in the other. **Every one of the four carries a
comment at its call site saying *why* it is inverted**, and that rule generalises: when a new file
deliberately contradicts an existing one, the contradiction is the thing that needs the comment.

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
  **four** limiters (`login_throttling`, `verification_email_resend`, `password_reset_request` and
  `password_reset_submit`), so a test driving `/login`, `/verify-email/resend`, `/forgot-password`
  or either reset route needs it. The cheap proof that you got it right is to **run `make test`
  twice in a row** — a second run that fails is the classic symptom.
  **The session is load-bearing in a functional test now**, because the reset flow stashes its token
  there. Test sessions use `session.storage.factory.mock_file`, which persists across requests within
  **one** `KernelBrowser` — so a test that creates a second client, or reboots the kernel, silently
  loses the flow. Keep a reset scenario inside one client.
  **A repository fetched once in `setUp()` serves entities from Doctrine's identity map**, so a read
  taken after an HTTP-driven mutation can hand back the pre-request object rather than the committed
  row. Fetch a fresh repository from the container immediately before any assertion that must reflect
  a request that just finished.
- **Swapping an adapter inside a test needs two things, and each fails silently alone.** Target the
  **concrete class's** service id, never the port alias — Symfony's `ResolveReferencesToAliasesPass`
  rewrites alias references to their target at *compile* time, so a handler's constructor is already
  wired to the adapter's id before the test runs. And call `$client->disableReboot()` first, because
  `KernelBrowser` reboots the kernel before every request and discards container overrides.
- **A test encodes what the code *should* do — never what it was observed doing.** A test written by
  running the code and recording the answer has no source of truth independent of the code, so it can
  never disagree with it. Slice 2 shipped one: it asserted a 404 that directly contradicted an
  approved AC, with a confident message documenting the bug as the design, and stayed green for two
  commits. **When an AC and the implementation disagree, fix one of them on purpose and say which
  won** — do not write the test that ratifies the accident. Where two responses must be
  indistinguishable, assert them against *each other* live, not against two copies of a literal that
  can drift apart while both stay green.
- **A comment or docblock claiming coverage the assertion cannot deliver is the same defect one level
  up.** If a test's docblock names a regression class, verify it actually fails on that regression —
  twice this slice a docblock named a failure mode that was structurally invisible to its assertion.

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
- **PHP scans `conf.d` alphabetically and last-write-wins, so an override file must sort *after* what
  it overrides — and `-` (0x2D) sorts before `.` (0x2E).** `zz-app-dev.ini` therefore loaded *before*
  `zz-app.ini` and every key both files set was silently reverted to the production value. The
  casualty was `opcache.validate_timestamps`: dev ran with it **Off** against a live-mounted `./src`,
  so edited code kept serving the previous version until the container was restarted. **Manual
  verification then exercises code that is not on disk — in both directions**: during slice 3's
  `/verify` it produced one false "the fix isn't working" (the code was already correct) and one
  false pass. `make test` is immune (`opcache.enable_cli = 0`, fresh process per run), which is
  exactly what made it dangerous — the automated gate stayed honest while hand-checks lied. Fixed
  2026-07-30 by installing the dev overrides as `zzz-app-dev.ini`. **When a hand-check contradicts a
  green suite, suspect the runtime before the code.**
- **`DEFAULT_URI` decides every absolute URL built outside an HTTP request** — the Messenger worker
  and every console command. Left at the skeleton's `http://localhost`, every link in every outgoing
  mail points somewhere unreachable, and **no functional test catches it**, because a test runs
  inside a simulated request where the fallback never fires. Set it per environment.
- **`getClientIp()` is the proxy's address until `framework.trusted_proxies` says otherwise** — so a
  rate limiter keyed on it is one global bucket, not one per visitor. Behind Traefik → nginx →
  php-fpm this made both limiters site-wide: five resend POSTs per hour from *anyone* would 429
  *everyone*. No test can catch it — `KernelBrowser` synthesises `REMOTE_ADDR=127.0.0.1`, a value
  that never occurs in production. Configured in `config/packages/framework.yaml`; dev and test
  deliberately disable trust, because dev publishes nginx straight to `127.0.0.1:8080` with no proxy
  in front, and trusting `X-Forwarded-For` there would let anyone reaching that port forge their own
  client IP.
- **Symfony owns client-IP/scheme/host reconstruction — nginx must NOT run `ngx_http_realip_module`.**
  `set_real_ip_from`/`real_ip_header` rewrite `$remote_addr`, which is what `fastcgi_params` passes as
  `REMOTE_ADDR`. Symfony then compares a *public* IP against the private-range trust list,
  `isFromTrustedProxy()` returns false, and `X-Forwarded-Proto`/`-Host` are never read — so
  `getClientIp()` comes out right by accident while `isSecure()` and `getSchemeAndHttpHost()` stay
  wrong, silently un-meeting the precondition `csrf.yaml` depends on. nginx logs the header directly
  (`xff=$http_x_forwarded_for`) instead. Two trust layers that each look right in isolation is the
  trap.
- **`private_ranges` cannot be passed through `%env()%`.** FrameworkBundle special-cases the keyword
  in a `beforeNormalization()` that runs at *compile* time, over the raw value — which is the
  unresolved placeholder. `.env.example` therefore spells the full `IpUtils::PRIVATE_SUBNETS` CIDR
  list out literally. Do not "simplify" it back.
- **A bare `%env(FOO)%` in config makes `FOO` a boot-time dependency of every kernel — including the
  worker, console commands, CI, and `docker build`.** A missing var is `EnvNotFoundException`, not a
  fallback. This took `messenger-worker` down while `make check` stayed green (it only `exec`s into
  `app`) and `/health/ready` stayed 200 (it probes Postgres and Redis, not queue depth). Use
  `%env(default::FOO)%` for optional infrastructure vars — then still set the var everywhere,
  because the fallback converts a loud outage into a silent misconfiguration. **When you add a
  required input to the boot path, enumerate every context that boots, not every context that serves
  traffic.**
- **Redis is now load-bearing for account recovery, not merely for its throttling.** The reset flow
  stashes the plaintext token in the **session** between `GET /reset-password/{token}` and the form
  it redirects to, so that a live account-takeover credential never sits in the URL of a page the
  user types into ([ADR-0011](./docs/adr/0011-password-reset-challenges-modelled-in-the-domain.md)
  decision 8). Sessions are Redis-backed, so **Redis down breaks password reset outright** — the
  route answers with the neutral invalid-link redirect, which is indistinguishable from a bad token,
  so the failure presents to the user as "your link doesn't work" and to the operator as nothing at
  all. Accepted knowingly; recorded here because it is the cost side of that decision and is easy to
  forget when reading only the benefit.
- **A route requirement's failure mode is a bare 404 that no `catch` can convert.** `{token}` on
  `/reset-password/{token}` is deliberately only `[^/]+`, with the format gate living in the
  `ResetToken` value object, so a mangled token reaches the controller and gets the same neutral
  invalid-link response as an unknown one. A stricter `requirements` regex would dead-end exactly the
  people who need the form (mail clients hard-wrap long lines) and would make the controller's
  `InvalidResetToken` arm dead code. **Nothing will tell you**: every test using a well-formed token
  passes with the strict regex in place.

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
