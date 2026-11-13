# Task List: observability-sentry

> Ordered, small tasks. Each should be reviewable in < 5 minutes and is one commit on
> `feature/observability-sentry`. Run `make check` before each commit. Check off as you go.

**BLOCKED on sign-off** of the [technical plan](./technical-plan.md) §Decisions and the open
questions in its §Risks (notably #2, `user_id` in events).

**Rule for this slice:** no file under `src/src/Domain/` or `src/src/Application/` is added or
changed (AC-19). Any task that seems to need one is a plan error — stop and re-plan.

## Decisions & prerequisites

- [ ] **T0 (human via `/adr`):** ADR-0013 *Error tracking: hosted Sentry (EU), one capture path
      through Monolog, scrub before send* — Decisions 1–7 + rejected alternatives (self-hosted,
      GlitchTip, bundle listeners + channel allowlist, `sentry:test` as proof). Same commit:
      Constitution §3 row *Error tracking | Sentry (hosted, EU) via sentry-symfony → Monolog | 0013*.
      **No code.** (AC-20)

## Domain

- [x] — *(nothing: no aggregate, VO, event or port — feature spec §Why no context)*

## Application

- [x] — *(nothing: no command, query or handler)*

## Infrastructure

- [x] **T1 (symfony-dev):** `composer require sentry/sentry-symfony` in the `app` container. Set
      `bundles.php` to `['all' => true]`. Replace the recipe's `sentry.yaml` with the minimal
      *disabled-safe* config: `dsn: '%env(default::SENTRY_DSN)%'`, `register_error_listener: false`,
      `register_error_handler: false`, `messenger.enabled: false`, `tracing.enabled: false`, options
      `environment`, `release: '%env(default::SENTRY_RELEASE)%'`, `send_default_pii: false`,
      `max_request_body_size: 'never'`, `enable_logs: false`, `enable_metrics: false`,
      `http_connect_timeout: 1`, `http_timeout: 2`; no `logger`. `src/.env`: `SENTRY_DSN=` with
      comment. Run `config:dump-reference sentry` and record in the commit message any option name
      that differed from the plan. (AC-8, AC-13 partial)
- [x] **T2 (symfony-dev):** `Infrastructure/Shared/Observability/SentryEventScrubber.php` —
      invokable `(Event, ?EventHint): ?Event` implementing plan Decision 4 steps 1–7; total over the
      event shape (never throws). Wire as `options.before_send`. Docblock names the reset-token
      journey and why `user_id` is allowed. (AC-3, AC-9, AC-10)
- [x] **T3 (symfony-dev):** `Sentry\Monolog\Handler` service in `sentry.yaml` (hub, `Level::Error`,
      bubble true, fillExtraContext true) + one top-level `monolog.handlers.sentry` (`type:
      service`) with a comment on why it is outside `fingers_crossed`, has no channel filter, and
      why the bundle's own listeners are off. No other line of `monolog.yaml` changes.
      (AC-1, AC-2, AC-4, AC-5, AC-6, AC-7)
- [x] **T4 (symfony-dev):** `tests/Fixture/RecordingSentryTransport.php` (instance state, `events()`
      accessor); `when@test` in `sentry.yaml` sets `options.transport` to it and declares it
      `public`; `.env.test` `SENTRY_DSN=https://public@sentry.invalid/1` with a comment on why both
      guards exist. (AC-14)
- [x] **T5 (symfony-dev):** `Infrastructure/Shared/Observability/SentryCheckCommand.php` —
      `muzbar:ops:sentry-check` per plan Decision 7 (logger path, canaries, flush, event id, exit
      1 when disabled). (AC-17)
- [x] **T6 (devops):** Dockerfile production stage last lines `ARG SENTRY_RELEASE=unknown` +
      `ENV SENTRY_RELEASE=${SENTRY_RELEASE}`; `deploy.yml` `build-args: SENTRY_RELEASE=${{
      github.sha }}`. Verify locally: `docker build --target production` with **no** build-arg
      succeeds and `printenv SENTRY_RELEASE` → `unknown`. (AC-13 build, AC-15)
- [ ] **T7 (devops):** `.env.example`: `SENTRY_DSN=` + comment (EU-region DSN from the project
      settings; empty disables; never add `SENTRY_RELEASE` — `env_file:` outranks the image `ENV`).
      `access-setup.md` `.env` bootstrap gains the line. `docker-compose.yml`: comment on
      `messenger-worker` that the DSN arrives via the shared `env_file:`. Run the AC-13 boot matrix
      by hand (`env -u SENTRY_DSN APP_ENV=prod php bin/console about`, `cache:clear --env=prod`,
      `up -d` without the line → both containers `running` after 60 s) and paste results in the
      commit message. (AC-13)

## Tests (qa)

- [ ] **T8 (qa):** `tests/Unit/Infrastructure/Shared/Observability/SentryEventScrubberTest.php` —
      canary-in / canary-out over every field in AC-9; 4xx → null, 503 → kept; `user_id` survives;
      minimal empty event does not throw. (AC-3, AC-9, AC-10)
- [ ] **T9 (qa):** `tests/Integration/Observability/SentryWiringTest.php` — effective client
      options (AC-8, AC-16 values), both test-env guards (AC-14), level threshold on `pruning` and
      `messenger` channels (AC-6), throwing console command → 1 event (AC-5).
- [ ] **T10 (qa):** `tests/Integration/Observability/MessengerFailureReportingTest.php` — real
      `WorkerMessageFailedEvent` for `async`: retry → 0 events; exhausted → 1 event with message
      class + exception type, SMTP-style canary address scrubbed. (AC-4, AC-12)
- [ ] **T11 (qa):** `tests/Functional/Observability/SentryCaptureTest.php` — AC-1 (throwing mailer
      in `NotifyUserOnPasswordChanged`, `extra.user_id`), AC-2 (uncaught 500 → exactly 1), AC-3
      (404/405 → 0), AC-11 (end-to-end PII). `disableReboot()`, concrete service ids,
      `ClearsRateLimiters`, one client per scenario.
- [ ] **T12 (qa):** `tests/Functional/Observability/SentryCheckCommandTest.php` — both branches
      (AC-17). Then **non-vacuity pass**: temporarily remove the Monolog handler, the 4xx rule and
      the e-mail regex in turn; confirm the named tests fail; restore. Record it in the commit
      message. Run `make test` **twice** in a row.

## Docs

- [ ] **T13 (devops):** `infrastructure.md` — rewrite §Monitoring's Sentry bullet; new *Sentry
      (runbook)* section: account setup (EU region, DPA, spike protection, alert rule *new issue +
      regression → e-mail*), where the DSN lives, `sentry-check` in both containers, the exact
      sent/never-sent list, and **what Sentry does not see** (crash loops, pre-boot failures).
      Footgun table row for `SENTRY_RELEASE` in `.env`. (AC-20)
- [ ] **T14 (symfony-dev):** CLAUDE.md (status line, Sentry convention block incl. "anything at
      `error` leaves the box after scrubbing", commands, `SENTRY_RELEASE` footgun); roadmap
      carry-over item 2 closed with date, crash-loop remainder pointed at the Phase 2 readiness
      item; ADR-0010 dated note; FORboehpyk.md chapter (the six alerts nobody read, why one path,
      the scrubber-as-second-line lesson). (AC-20)

## Verify

- [ ] **T15 (devops, needs owner):** owner creates the EU sentry.io project and puts `SENTRY_DSN` in
      the box `.env`; deploy; run `sentry-check` in `app` and `messenger-worker`; confirm both
      events in Sentry with canaries scrubbed, `environment: prod`, release = deployed SHA;
      unroutable-DSN timing check. Record event ids. (AC-15, AC-16, AC-18)
- [ ] **T16:** `/verify` → reviewer PASS, all ACs checked, docs updated. (AC-21)
