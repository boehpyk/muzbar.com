# ADR-0013: Error tracking — hosted Sentry (EU), one capture path through Monolog, scrub before send

- **Status:** Accepted
- **Date:** 2026-11-13
- **Established by** the `observability-sentry` slice (spec: `docs/specs/observability-sentry/`).
- **Discharges** [ADR-0010](./0010-event-delivery-and-transactional-mail.md)'s Sentry consequence for
  the *swallowed listener* and *exhausted transport* cases — see the dated note there. Crash loops
  and queue age are **not** discharged.
- **Changes Constitution §3:** adds the *Error tracking* row.

## Context

By slice 5 the codebase wrote six `error`-level log lines that nobody could read — two of them
(`NotifyUserOnPasswordChanged`) with **no compensating control at all**. Slice 2's verification
spent a session with `messenger-worker` crash-looping while `make check` and `/health/ready` were
green. The signals existed; they ended at stderr on a box nobody tails.

Forces: one operator, one VDS (Constitution §5 — no extra moving parts); EU users and GDPR
(§8); a reset-token flow whose secret passes through a URL, a `Referer` and a session
(ADR-0011 decision 8), so anything that ships request data off the box must be proven not to carry
it; and the house footgun *"a handler wired in config is not proof records reach it"*.

## Decision

1. **Hosted sentry.io, EU data region**, free plan, DPA accepted, spike protection on. The SDK
   speaks the Sentry protocol, so moving to GlitchTip or self-hosting later is a DSN change.
2. **`sentry/sentry-symfony`, with exactly one capture path: a `Sentry\Monolog\Handler` at
   `ERROR`**, top-level, beside `fingers_crossed`, no channel filter, `bubble: true`. Every other
   capture path is **off**: `register_error_listener`, `register_error_handler`,
   `messenger.enabled`, `tracing.enabled`. Symfony already logs uncaught HTTP/console exceptions at
   `critical`, so leaving the bundle's listeners on would mean two events per failure.
3. **4xx is not ours.** Symfony logs 4xx `HttpExceptionInterface` at `error`; the scrubber drops
   them (status < 500). 5xx is kept.
4. **Messenger reports exhausted failures only**, for zero code: `SendFailedMessageForRetryListener`
   logs `warning` while retrying and `critical` on giving up, so the `ERROR` threshold *is* the rule.
5. **Scrub before send, assuming nothing else worked.** `send_default_pii: false` and
   `max_request_body_size: never` are set, but the `before_send` scrubber
   (`Infrastructure/Shared/Observability/SentryEventScrubber`) is written as if they were not: it
   removes the `user`, cookies, body, query string, frame `vars`, breadcrumbs and non-allowlisted
   headers; filters token path segments and e-mail addresses in every string; and filters
   values under keys matching `/pass|token|secret|sess|cookie|csrf|auth|email|dsn/i`.
   **`user_id` (a UUIDv7) is deliberately sent** — pseudonymous, covered by the DPA, and what makes
   an alert actionable.
6. **Boot path:** `dsn: '%env(default::SENTRY_DSN)%'` — empty or absent disables the SDK in every
   context that boots (FPM, worker, console, `docker build`, CI). `release` is the image's git SHA,
   baked in at build (`ARG`/`ENV SENTRY_RELEASE`), never in `.env` — `env_file:` outranks image
   `ENV` and would pin every release to one value. `environment` is `APP_ENV`. Sends are synchronous
   with 1 s connect / 2 s total timeouts. No tracing.
7. **Proof, not config:** tests run against an in-memory `RecordingSentryTransport` and an
   `.invalid` DSN (either guard alone prevents a send), and were shown non-vacuous by removing the
   handler, the 4xx rule and the e-mail regex in turn. In production, `muzbar:ops:sentry-check` logs
   a canary through the real Monolog → scrubber → transport path in each container and prints the
   event id — chosen over the bundle's `sentry:test`, which calls `captureMessage()` directly and
   so proves neither the handler nor the scrubber.

## Alternatives

- **Self-hosted Sentry** — Kafka, ClickHouse, Snuba, ~16 GB RAM: bigger than the box it would
  watch, and a second system to keep alive. Rejected.
- **GlitchTip** on the VDS — lighter, protocol-compatible, but still a new service, upgrade and
  backup surface. Rejected for now; remains a DSN change away.
- **Keep the bundle's listeners and give the Monolog handler a channel allowlist** for what they
  don't cover — an allowlist that silently goes stale the day a channel appears, and two paths to
  keep in privacy lockstep. Rejected.
- **The bundle's Messenger integration** — its default `capture_soft_fails: true` reports every
  retry. Rejected in favour of decision 4.
- **`ignore_exceptions` or `framework.exceptions` log levels for 4xx** — a class list that misses
  new 4xx types, or a change that also alters stderr logs. Rejected in favour of decision 3.
- **`SENTRY_RELEASE` written into `.env` by the deploy script** — a hand rollback that edits
  `MUZBAR_IMAGE` would leave the release lying. Rejected.

## Consequences

- **Implicit contract for all future code: anything logged at `error` or above leaves the box
  after scrubbing.** Log at `error` only what an operator must act on; put identifiers under neutral
  context keys; never put an address or secret in a log message. The scrubber is the second line,
  not the first.
- Log context arrives at `extra['monolog.context']`, not `extra`. Messenger's exhausted-failure
  event carries the raw `{class}`/`{error}` template as its message; Sentry groups by exception.
- A Sentry outage costs each `error` record up to ~2 s, synchronously. Errors are rare; accepted.
- **Sentry does not see** crash loops, failures before kernel boot, or a stopped worker — the most
  cited case in ADR-0010. Those remain owned by the deploy image check and the Phase 2 queue-age
  readiness item.
- Watch: whether production events carry request data (the SDK's `RequestIntegration` reads the
  global hub; test events had none) — check at the first deploy with a DSN. Bundle/SDK upgrades may
  rename options; the wiring test asserts *effective* option values, so a silently ignored key fails.
