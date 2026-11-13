# Technical Plan: observability-sentry

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order — which for this slice means two empty sections and one full one.

## What exists today (verified 2026-11-13)

**`error`-level records already written** (the signals this slice exists to read):

| Call site | Channel | Context | Compensating control |
|---|---|---|---|
| `IssueVerificationOnUserRegistered` catch | `app` | `user_id`, `exception` | resend form |
| `NotifyUserOnPasswordChanged` — user not found | `app` | `user_id` | **none** |
| `NotifyUserOnPasswordChanged` catch | `app` | `user_id`, `exception` | **none** |
| `PasswordResetController` — transport hand-off failed | `app` | `reason`, `exception` | none (user waits) |
| `EmailVerificationController` / `PasswordResetController` — "unreachable state" | `app` | `reason`, `exception` | n/a |
| `PruneChallengesCommand` — run failed | `pruning` | `exception`, `dry_run` | exit 1 (cron not installed) |

**Records Symfony writes that we also want** (checked in `vendor/`):

- HTTP uncaught: `HttpKernel\EventListener\ErrorListener::resolveLogLevel()` → `critical` for non-HTTP
  exceptions and 5xx, **`error` for 4xx `HttpExceptionInterface`** — so 404s/429s would reach an
  `ERROR` handler. Hence the scrubber's 4xx drop (Decision 2c).
- Console uncaught: `Console\EventListener\ErrorListener` → `critical`.
- Messenger: `SendFailedMessageForRetryListener` → `warning` *"Sending for retry #n"* when it will
  retry, `critical` *"Removing from transport after n retries. Error: "{error}""* when exhausted.
  The `{error}` placeholder is *not* interpolated (no PSR-3 processor on the Sentry handler); the
  exception message reaches the event via `monolog.context['error']` and the exception value — an
  SMTP refusal routinely contains the recipient address (Decision 4). *(Corrected at verify.)*

**Monolog today:** `prod`/`test` top level is `fingers_crossed` (`action_level: error`, channels
`!pruning` and `!deprecation`/`!event`) → `nested` (stderr JSON), plus `console`, `deprecation`, and
the dedicated `pruning` handler (ADR-0012). Monolog 3.10.

**PHP ini:** `zend.exception_ignore_args` is not set, so PHP's built-in default (`Off`) applies and
**stack traces carry function arguments**. `#[\SensitiveParameter]` guards the known secrets (20
uses), but Sentry frame `vars` are removed by the scrubber regardless (Decision 4).

**Boot contexts** (the CLAUDE.md "enumerate every context that boots" rule): `app` (FPM, prod),
`messenger-worker` (prod), console via `docker compose exec app` (deploy's `migrate`/`cache:clear`,
manual runbook commands, the future cron), `docker build` (Composer's `cache:clear` auto-script boots
a prod kernel with **no** root `.env`), CI (`cache:warmup` in dev, PHPUnit in test, on the bare
runner), local dev (`app` + `messenger-worker`), test.

## Decisions (recommendation first; all go into ADR-0013)

### Decision 1 — Hosted sentry.io, **EU data region**, free Developer plan

- **Recommend hosted.** Self-hosted Sentry needs Kafka, ClickHouse, Snuba and ~16 GB RAM — larger
  than the box it would watch, and a second system the one operator must keep alive (who watches the
  watcher?). GlitchTip (Sentry-protocol-compatible, Postgres+Redis) is lighter but is still a new
  service, upgrade surface and backup on a shared box (Constitution §5: one VDS, no extra moving
  parts). Because the SDK speaks the Sentry protocol, moving to GlitchTip later is **a DSN change**,
  so the lock-in cost of choosing hosted now is near zero.
- **EU region** (`*.de.sentry.io` DSN): users are EU (Constitution §8, GDPR). Accept Sentry's DPA in
  org settings. Free plan: 5k errors/month, one user — ample for a pre-launch site; spike
  protection on.
- **Data that leaves the box** (documented verbatim in the runbook): exception class, scrubbed
  message and exception values, stack frames (file path, line, function, ±5 lines of *our* source —
  no argument values), route/transaction name (scrubbed), request method + scrubbed URL path +
  allowlisted headers, `extra` from the log context after key filtering (notably `user_id` UUIDs,
  `reason`, `dry_run`), `environment`, `release`, `server_name` (container hostname), PHP/OS
  versions. **Never:** e-mail addresses, tokens, passwords, session ids/cookies, IPs, request bodies,
  query strings, `user` objects.

### Decision 2 — `sentry/sentry-symfony`, **one capture path: Monolog at `ERROR`**

a. **Install the bundle**, enabled for `all` envs in `bundles.php` (the Flex recipe registers it for
   `prod` only — change it, or the test env cannot prove the wiring). Rewrite the recipe's
   `sentry.yaml`; do not keep its `when@prod`-only shape.

b. **Capture through Monolog only.** A `Sentry\Monolog\Handler` service (`$hub` =
   `Sentry\State\HubInterface`, `$level` = `Monolog\Level::Error`, `$bubble` = true,
   `$fillExtraContext` = true) registered as a **top-level** `type: service` handler with **no
   channel filter** in a single top-level `monolog.handlers.sentry` block (applies to dev/test/prod;
   dev is disabled by its empty DSN). It sits beside `fingers_crossed`, not inside it: the buffer is
   irrelevant to it because it only ever acts at `ERROR`+, and `bubble: true` means `nested` and
   `pruning` still get every record (AC-7). The `pruning` channel is deliberately **not** excluded —
   its `error` (run failed) is exactly what should alert; its `info`/`warning`s are below the level.
   Then switch **off** every other capture path:

   | Bundle setting | Value | Why |
   |---|---|---|
   | `register_error_listener` | `false` | Symfony already logs the uncaught exception at `critical`; on would be 2 events per 500. |
   | `register_error_handler` | `false` | Same, for the SDK's PHP error/fatal handlers; Symfony's `ErrorHandler` logs to the `php` channel. |
   | `messenger.enabled` | `false` | Its default `capture_soft_fails: true` reports **every retry**; Messenger's own `critical` on exhaustion is the one we want (Decision 5). |
   | `tracing.enabled` | `false` | Decision 6; also disables the DBAL/Twig/cache/console tracing listeners. |
   | `register_monolog_handler` | `false` (default) | Experimental; the explicit service is stable and testable. |

   **Rejected:** keeping the bundle's listeners and filtering the Monolog handler to "the channels
   they don't cover" — an allowlist of channels that silently goes stale the day a new channel
   appears, and two paths to keep in privacy-lockstep.

c. **4xx is not an error we own.** Because Symfony logs 4xx `HttpExceptionInterface` at `error`, the
   scrubber returns `null` when `EventHint::$exception` is an `HttpExceptionInterface` with status
   < 500. Done in the scrubber (one place, unit-testable) rather than `ignore_exceptions` (class list,
   misses new 4xx types) or `framework.exceptions` log-level mapping (would also change stderr logs).

d. **The footgun, honoured:** "a handler wired in config is not proof records reach it" — every
   capture claim in AC-1…AC-6 is a test against the recording transport, and T12 proves the tests
   are not vacuous by removing the handler and watching them fail.

### Decision 3 — `SENTRY_DSN` boot path: `%env(default::SENTRY_DSN)%` everywhere

`dsn: '%env(default::SENTRY_DSN)%'` set **explicitly** (the bundle otherwise silently falls back to
reading `SENTRY_DSN` itself, which hides the decision). Empty → SDK disabled. Per context:

| Context | Source of `SENTRY_DSN` | Result |
|---|---|---|
| `app` prod | box `.env` via `env_file:` | enabled |
| `messenger-worker` prod | **same** `env_file: .env` | enabled — no per-service `environment:` line needed (unlike `TRUSTED_PROXIES`); stated in a compose comment so nobody adds a divergent one |
| console / deploy `exec` / future cron | inherits `app` | enabled |
| `docker build` (`cache:clear` auto-script) | `src/.env` committed `SENTRY_DSN=` | disabled; `default::` also covers absence |
| CI (dev warmup, PHPStan) | `src/.env` | disabled; nothing added to `ci.yml` |
| CI / local test | `.env.test` → `.invalid` DSN + recording transport | records, never sends (Decision 7) |
| local dev | `src/.env` empty; opt-in via `src/.env.local` | disabled by default |

`SENTRY_DSN` is a secret-ish value (it permits sending events to the project): box `.env` only,
never git, never a GitHub secret (the app's config lives on the box — cicd.md §Secrets).

### Decision 4 — PII: `send_default_pii: false` + a scrubber that assumes nothing else worked

Options: `send_default_pii: false`, `max_request_body_size: 'never'`, `enable_logs: false`,
`enable_metrics: false`, `before_send: App\Infrastructure\Shared\Observability\SentryEventScrubber`
(an invokable service, `__invoke(Event $event, ?EventHint $hint): ?Event`). The SDK's own
sanitising is treated as a bonus, not a guarantee — the scrubber is written as if `send_default_pii`
were true, so a future SDK default change cannot leak. It:

1. Returns `null` for 4xx (Decision 2c).
2. **Request:** URL path → token segments after `/reset-password/` and `/verify-email/` become
   `[Filtered]`; query string, cookies, body (`data`), `env` removed; headers reduced to an allowlist
   (`User-Agent`, `Accept`, `Accept-Language`, `Content-Type`, `Host`) — so `Referer` (which can hold
   the reset URL), `Cookie`, `Authorization`, `X-Forwarded-For` go.
3. **User:** removed entirely (`setUser(null)`). Our user identifier *is* an e-mail address.
4. **Strings everywhere** (message, formatted message, every exception value incl. chained ones,
   `transaction`, tags, string leaves of `extra` and `contexts`): e-mail regex → `[email]`, token-path
   regex → `[Filtered]`.
5. **Keys:** any `extra`/`contexts` key matching `/pass|token|secret|sess|cookie|csrf|auth|email|dsn/i`
   → `[Filtered]`. `user_id` is deliberately not matched (AC-10).
6. **Stack frames:** `vars` removed from every frame (args are captured because
   `zend.exception_ignore_args` is Off). Source context lines kept — they are our code, and code
   holds no secrets by convention.
7. **Breadcrumbs:** emptied. None are configured; emptying means none can leak if one is added.

The reset token's journey (ADR-0011 decision 8) is covered at both hops: `GET /reset-password/{token}`
(URL, `Referer` on the next hop, route-based transaction) and the session-stashed hop (no cookie, no
session id, no body).

### Decision 5 — Messenger: report exhausted failures only, via Messenger's own `critical` log

No custom `WorkerMessageFailedEvent` subscriber. Messenger's `SendFailedMessageForRetryListener`
already distinguishes the two cases by **level** — `warning` while retrying, `critical` when it gives
up — so the `ERROR` threshold *is* the "exhausted only" rule, for zero code. The bundle's Messenger
integration is disabled precisely because it does the opposite by default. Covers the ADR-0010
amendment's case (iii): SMTP refusal inside the worker.

### Decision 6 — Release = image SHA baked at build; environment = `APP_ENV`; **no tracing**

- `docker/php/Dockerfile` production stage, **last lines** (so earlier layers stay cached):
  `ARG SENTRY_RELEASE=unknown` / `ENV SENTRY_RELEASE=${SENTRY_RELEASE}`. `deploy.yml`
  `build-push-action` gains `build-args: SENTRY_RELEASE=${{ github.sha }}`. Config:
  `release: '%env(default::SENTRY_RELEASE)%'`, `environment: '%kernel.environment%'`.
- **Why baked, not written into `.env` by the deploy script:** the image then *knows* what it is. A
  hand rollback that edits `MUZBAR_IMAGE` would leave a `.env`-sourced release lying.
- **Footgun (goes into CLAUDE.md):** `env_file:` outranks the image's `ENV`, exactly as with
  `APP_ENV` — so a `SENTRY_RELEASE` line in the box `.env` would silently pin every release to one
  value. `.env.example` carries a comment saying never to add it.
- **Tracing off** (`tracing.enabled: false`, no `traces_sample_rate`/`profiles_sample_rate`): saves
  quota and request overhead; there is no traffic to measure yet. Revisit with the 200 ms search
  budget (Phase 2+) — a one-line change.
- **Timeouts:** `http_connect_timeout: 1`, `http_timeout: 2` (SDK defaults 2/5). Sends are
  synchronous at capture time, so this bounds what a Sentry outage can add to a failing request.

### Decision 7 — Proof: an operator command + tests against a recording transport

- **`muzbar:ops:sentry-check`** (`Infrastructure/Shared/Observability/SentryCheckCommand.php`).
  Chosen over the bundle's `sentry:test` because that calls `captureMessage()` directly and so proves
  neither the Monolog handler nor the scrubber — the two things most likely to be wrong. It injects
  `LoggerInterface` and `HubInterface` (never the `SentrySdk::` static — avoids cross-test leakage),
  returns 1 if `$hub->getClient()?->getOptions()->getDsn()` is null, else logs one `error` with
  context `{ "token": "CANARYTOKEN…", "note": "canary.user@example.invalid" }`, calls
  `$hub->getClient()->flush()`, prints `$hub->getLastEventId()`, returns 0 (1 if no id).
  Runbook: run it in `app` **and** `messenger-worker` after any deploy that touches config.
- **Test env:** `.env.test` `SENTRY_DSN=https://public@sentry.invalid/1` **and** `when@test` →
  `sentry.options.transport: App\Tests\Fixture\RecordingSentryTransport` (implements
  `Sentry\Transport\TransportInterface`; instance state, not static, so each kernel boot starts
  empty; `public` in `when@test: services:`). Belt and braces: either guard alone prevents a send;
  AC-14 tests both.
- **Verify option names against the installed bundle version at T1** (`config:dump-reference
  sentry`). Names above match the current `Configuration.php`; if one differs, change the name, not
  the decision.

### Decision 8 — ADR & documentation

New **ADR-0013 — Error tracking: hosted Sentry (EU), one capture path through Monolog, scrub before
send**, recording Decisions 1–7 and the rejected alternatives. It changes the stack table, so
Constitution §3 gains a row (amendment commit paired with the ADR, per §9). ADR-0010 gets a dated
note, not a rewrite: its Sentry consequence is discharged; crash loops and queue age remain.

## Domain layer (pure PHP)

- **Aggregate / entity changes:** none.
- **Value objects:** none.
- **Domain events:** none.
- **Ports:** none. No inside caller exists (see feature spec §*Why no context*). The scrubber's rules
  are about the shape of a vendor payload (`Sentry\Event`), so they are Infrastructure by
  definition — moving them inward would mean importing Sentry types into the Domain, which Deptrac
  forbids for exactly this reason.

## Application layer

- **Command/Query / Handler / Idempotency:** none.

## Infrastructure layer

- **Persistence:** none. No migration, no table.
- **HTTP / UI:** none.
- **Async / schedule:** none added. Messenger failures are observed through existing logs.
- **External:** Sentry, via `sentry/sentry-symfony` (pulls `sentry/sentry`). No HTTP client package
  needed beyond what the SDK brings — confirm at T1 that Composer adds nothing unexpected.
- **Files:**
  - `src/config/bundles.php` — `SentryBundle => ['all' => true]`.
  - `src/config/packages/sentry.yaml` — the whole Decision 2/3/4/6 config + the
    `Sentry\Monolog\Handler` service + `when@test` transport override, with comments in the house
    style explaining each "off".
  - `src/config/packages/monolog.yaml` — one top-level `handlers.sentry: { type: service, id:
    Sentry\Monolog\Handler }` block with a comment; nothing else touched.
  - `src/src/Infrastructure/Shared/Observability/SentryEventScrubber.php`
  - `src/src/Infrastructure/Shared/Observability/SentryCheckCommand.php`
  - `src/tests/Fixture/RecordingSentryTransport.php`
  - `src/.env` (`SENTRY_DSN=` with comment), `src/.env.test` (`.invalid` DSN with comment),
    `.env.example` (`SENTRY_DSN=` + EU-region hint + "never `SENTRY_RELEASE` here").
  - `docker/php/Dockerfile`, `.github/workflows/deploy.yml` (Decision 6).
  - `docker-compose.yml` — comment only on `messenger-worker` (DSN arrives via the shared
    `env_file:`).
- **DI wiring:** autowired; `before_send` references the scrubber's service id. **Do not** set
  `sentry.options.logger` or `sentry.logger` to the app logger (transport errors → `error` record →
  Sentry handler → transport … loop).

## Interface boundary & input contract

- **Env:** `SENTRY_DSN` (optional; empty/unset = disabled), `SENTRY_RELEASE` (image-baked; never in
  `.env`).
- **Console:** `muzbar:ops:sentry-check` — no arguments/options; exit 0 = event id printed, 1 =
  disabled or no event id.
- **Implicit contract for all future code:** *anything logged at `error` or above is sent to a third
  party after scrubbing.* So: log at `error` only what an operator must act on; put identifiers in
  context under neutral keys (`user_id`, `reason`); never put an address or secret in a log message
  even though the scrubber would catch it — the scrubber is the second line, not the first. Goes
  into CLAUDE.md.

## Data & migrations

- None.

## Test plan

- **Unit (no kernel):** `tests/Unit/Infrastructure/Shared/Observability/SentryEventScrubberTest.php`
  — AC-3 (scrubber half), AC-9, AC-10, minimal-event totality (failure contract 6). Each canary is
  asserted present in the input JSON before and absent from the output JSON after.
- **Integration (kernel):** `tests/Integration/Observability/SentryWiringTest.php` — AC-8, AC-14,
  AC-16 (option values), AC-6 (direct logger writes at warning/info on `pruning` and `messenger` →
  0 events; at error on `pruning` → 1), AC-5 (run a throwing command via `CommandTester` /
  `Application` with `setCatchExceptions(true)` so `ConsoleEvents::ERROR` fires).
- **Messenger (kernel):** `tests/Integration/Observability/MessengerFailureReportingTest.php` —
  AC-4/AC-12: dispatch `WorkerMessageFailedEvent` for receiver `async` through the real event
  dispatcher, once with no `RedeliveryStamp` (→ retry, 0 events) and once with a stamp at the
  configured max retries (→ exhausted, 1 event, sent to `failed`, rolled back by DAMA).
- **Functional:** `tests/Functional/Observability/SentryCaptureTest.php` — AC-1 (throwing mailer on
  the password-reset completion path, `disableReboot()`, concrete service id), AC-2 (a throwing
  double on a route whose controller does not catch it — qa picks and confirms the 500), AC-3 (404,
  405 → 0), AC-11 (SMTP-style message with the user's address; JSON free of address, token, session
  cookie value, password). One `KernelBrowser` per scenario; `ClearsRateLimiters` in `setUp()`.
- **Command:** `tests/Functional/Observability/SentryCheckCommandTest.php` — AC-17 both branches
  (disabled branch by overriding the hub/options in the container).
- **Non-vacuity (T12):** remove the Monolog handler → AC-1/2/4/5 fail; remove the 4xx rule → AC-3
  fails; remove the e-mail regex → AC-11/12 fail. Noted in the commit message.
- **Manual (devops, at deploy):** AC-13 (box without the line), AC-15, AC-16 (unroutable DSN),
  AC-18.

## Risks / open questions

1. **Bundle/SDK option drift.** Option names (`max_request_body_size: 'never'`, `enable_logs`,
   `enable_metrics`, `transport`) vary across SDK majors; the current bundle also introduces a
   `data_collection` block that may supersede legacy options. T1 pins what the installed version
   accepts; AC-8 asserts the *effective* values, so a silently ignored key fails a test.
2. **`user_id` leaves the box** (pseudonymous personal data under GDPR). Recommended: yes, it makes
   the alert actionable; covered by the DPA. **Needs the owner's call.**
3. **Account setup is a human step:** create the sentry.io org in the **EU** region, a PHP/Symfony
   project, accept the DPA, enable spike protection, set the alert rule (*new issue* + *regression*
   → e-mail), paste the DSN into the box `.env`. The slice can merge with Sentry disabled; AC-18 is
   then done at the first deploy after the DSN exists.
4. **Synchronous send latency:** with sentry.io down, each error-level record costs up to ~2 s.
   Errors are rare; bounded by Decision 6 timeouts. An async transport is not worth a queue here.
5. **Crash loops stay invisible** (failure contract 7). This is the most-cited motivating case in
   ADR-0010, so say it plainly in the runbook and roadmap: Sentry fixes the *swallowed* and *failed
   transport* cases, not the *never booted* case.
6. **Should dev send?** Recommended no (empty by default; opt-in via `.env.local`, tagged
   `environment: dev`).
