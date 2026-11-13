# Feature Spec: observability-sentry

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `observability-sentry` — closes the Phase 0 carry-over *"Sentry error tracking"*
  (deferred by decision 2026-07-25, overdue since 2026-07-28).
- **Bounded context(s):** **none.** This is an operations slice. No bounded context's Domain or
  Application code is added or changed (AC-19). See *Why no context* below.
- **Related PRD items:** none directly. Motivated by [roadmap](../../roadmap.md) Phase 0 carry-over
  item 2, [ADR-0010](../../adr/0010-event-delivery-and-transactional-mail.md) §Consequences and its
  2026-11-13 amendment (worker-side delivery failures are *"what Sentry must watch"*), and
  [infrastructure.md](../../infrastructure.md) §Monitoring.
- **Governing ADRs:** ADR-0003 (one VDS, one operator), ADR-0010, ADR-0012 (the `pruning` channel's
  dedicated handler). **Proposes ADR-0013** — *Error tracking: hosted Sentry, one capture path
  through Monolog, scrub before send* (technical plan §Decisions).
- **Status:** Approved 2026-11-13 — with defaults: `user_id` sent (pseudonymous, covered by the DPA); Sentry account + DSN set up by the owner (slice may merge disabled; AC-18 waits for the DSN); dev does not send by default (opt-in via `src/.env.local`); crash loops before kernel boot stay out of Sentry's reach (accepted, owned by the deploy image check and the Phase 2 queue-age readiness item).
- **Date:** 2026-11-13

> **The one thing to know first:** the application already *writes* every signal this slice needs.
> Six call sites log at `error` today, written on purpose "to be the Sentry alert it will become";
> Symfony itself logs uncaught HTTP/console exceptions at `critical` and Messenger logs an exhausted
> retry at `critical`. Nothing reads any of them. This slice adds **one reader** — a Monolog handler
> at `ERROR` — plus the scrubber that decides what of each record may leave the box, and the proof
> that both actually work. It deliberately adds **no** second capture path (the bundle's own error
> listener and Messenger listener are switched off), because two paths means duplicate events and
> two places to get privacy wrong.

### Why no context

Error tracking is a cross-cutting **adapter** concern. No aggregate has an invariant about it, no
value object models it, no domain event announces it, and nothing *inside* the hexagon calls it —
it observes the PSR-3 logger that Infrastructure already writes to. Slice 5 taught the rule: *a port
exists so the inside can reach the outside; when no inside code is the caller, a port is an
interface with one implementation and no client.* The Domain must not know Sentry exists; if a
future Domain class needed to "report an error" it would throw, and the throw is what gets reported.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Event** (Sentry) | One payload sent to Sentry, built from one Monolog record at `ERROR` or above. | A **domain event** (ADR-0008). Never say "event" unqualified in this slice's code comments. |
| **Capture path** | The single route by which an error becomes a Sentry event: `LoggerInterface` → Monolog → `Sentry\Monolog\Handler` → `before_send` scrubber → transport. | The bundle's own `ErrorListener` / `MessengerListener`, which are **disabled** here. |
| **Scrubber** | The `before_send` callable that removes personal data and secrets from an event, and drops client-fault (4xx) events, before anything leaves the process. | Monolog processors (they shape log lines; the scrubber shapes only what goes to Sentry). |
| **Canary** | A planted, recognisable literal (e.g. `canary.user@example.invalid`, `CANARYTOKEN…`) used by tests and the operator check to prove a value was scrubbed. | Real user data — never used in a test. |
| **Exhausted failure** | A Messenger message whose retries are used up and which is moved to the `failed` transport (Messenger logs it at `critical`). | A **retry** (logged at `warning`; never reported). |
| **Disabled** | `SENTRY_DSN` empty or unset: the SDK has no DSN and sends nothing; the app boots normally. | Broken. Disabled is a legitimate state (dev, CI, build). |
| **Release** | The git SHA of the image, baked in at build time as `SENTRY_RELEASE`. | The `latest` tag, or the value in the box's `.env`. |

## User story

As the **operator** (the one person running muzbar), I want **every error-level failure — in a web
request, a console command or the Messenger worker — to arrive in an inbox I already read, with no
user's email, token, password or session in it**, so that **silent-by-design failure paths (swallowed
listener exceptions, messages in the `failed` transport) stop being silent to me, without creating a
privacy leak to a third party**.

Secondary (Constitution §2): as the **developer**, I want this slice to show that an operations
concern can be added with **zero Domain and zero Application change**, and that "a handler wired in
config" is a claim to be tested, not assumed (CLAUDE.md, Monolog footgun).

## In scope

- `sentry/sentry-symfony` installed; bundle enabled in **all** environments (so the test env can
  prove the wiring), with `SENTRY_DSN` read through `%env(default::SENTRY_DSN)%`.
- One Monolog handler (`Sentry\Monolog\Handler`, level `ERROR`, bubbling) receiving every channel.
- Bundle's `register_error_listener`, `register_error_handler`, `messenger.enabled` and
  `tracing.enabled` all **off**; `send_default_pii`, logs, metrics off; request bodies never sent.
- A `before_send` scrubber in `Infrastructure/Shared/Observability/` + its unit test.
- Release = image git SHA (Dockerfile `ARG` → `ENV SENTRY_RELEASE`); environment = `APP_ENV`.
- Test env: a recording in-memory transport and a `.invalid` DSN — no event can reach a socket.
- `muzbar:ops:sentry-check` console command: the operator-runnable proof an event arrives.
- Docs: ADR-0013, Constitution §3 row, CLAUDE.md, infrastructure.md runbook, access-setup.md `.env`
  bootstrap, roadmap carry-over closed, ADR-0010 note, FORboehpyk.md.

## Non-goals (explicit — hold the line)

- **No self-hosted Sentry or GlitchTip.** (Plan Decision 1; the DSN makes it a one-line swap later.)
- **No performance tracing, profiling, Sentry Logs or Metrics.** Errors only; quota stays for errors.
- **No browser/JS SDK.** There is no public UI yet beyond the coming-soon stub.
- **No breadcrumbs.** No `BreadcrumbHandler`; the scrubber empties breadcrumbs as defence in depth.
- **No Sentry Crons / check-ins for the pruning job.** Its cron is not installed (roadmap
  §deferred activations). Revisit when that cron is switched on.
- **No queue-age readiness check and no worker crash-loop detection.** A worker that cannot boot a
  kernel cannot log, so Sentry is structurally blind to it (failure contract row 7). That stays the
  deploy's image check + the Phase 2 readiness item.
- **No `pcntl` / graceful-shutdown fix** (ADR-0010 consequence, separate devops item).
- **No change to existing log output.** `main`/`nested`/`deprecation`/`pruning` handlers unchanged.
- **No reporting of 4xx** (404/405/429/403…): client faults, not ours.
- **No alert routing beyond Sentry's default e-mail** (no Telegram/Slack integration in this slice).

## Acceptance criteria (the Definition of Done checklist)

"Exactly one event" is asserted against the test env's recording transport. Every privacy AC is
asserted by **serialising the recorded event to JSON and searching for each canary literal**, after
first asserting the canary was present in the input (so the test cannot pass vacuously).

**Capture — what reaches Sentry**

- [ ] **AC-1** — A record at `error` on any channel produces **exactly one** event, with Sentry level
      `error`, the record's message, and — when the record's context has `exception` — that
      exception's class as the event's exception type. Proven on a real call site:
      `NotifyUserOnPasswordChanged` with a throwing mailer double → 1 event whose `extra['monolog.context']['user_id']`
      equals the user's UUID. *(Amended at implement: `Sentry\Monolog\Handler` nests the record
      context under `extra['monolog.context']`.)*
- [ ] **AC-2** — An uncaught exception in an HTTP request (500) produces **exactly one** event, not
      two (the bundle's error listener is off; Symfony's `ErrorListener` `critical` log is the only
      path).
- [ ] **AC-3** — 4xx responses produce **zero** events: an unknown route (404) and a wrong method
      (405) proven functionally; `TooManyRequestsHttpException` (429), `AccessDeniedHttpException`
      (403) and `BadRequestHttpException` (400) proven at the scrubber (returns `null`). A
      5xx `HttpException` (e.g. 503) is **not** dropped.
- [ ] **AC-4** — Messenger: a failed message that **will be retried** produces **zero** events; the
      same message on its **final** failure (moved to the `failed` transport) produces **exactly
      one** event whose `extra['monolog.context']['class']` names the message class and whose
      exception chain contains the handler's exception. *(Amended at implement: the event message is
      Messenger's raw `{class}`/`{error}` template — no PSR-3 interpolation — and Sentry groups by
      the exception, not the message, so the template does not merge distinct failures.)* Proven through Symfony's real `SendFailedMessageForRetryListener`, not a mock of it.
- [ ] **AC-5** — An uncaught exception in a console command produces **exactly one** event.
- [ ] **AC-6** — Records below `error` produce **zero** events: the pruning command's `warning`s, its
      `info` run line, and Messenger's retry `warning`.
- [ ] **AC-7** — Existing logging is unchanged: the full pre-existing suite passes **without editing
      any existing test**, including the pruning tests that prove the `info` line reaches the
      `pruning` handler (ADR-0012), and `monolog.yaml`'s existing handlers are byte-identical apart
      from the added `sentry` handler.

**Privacy — what never leaves the box**

- [ ] **AC-8** — Client options, read from the container in a test: `send_default_pii === false`,
      `max_request_body_size === 'never'`, `traces_sample_rate` null or 0, no `profiles_sample_rate`,
      `enable_logs` false; bundle tracing, error listener, error handler and Messenger listener off;
      `options.logger` **not** set (no feedback loop).
- [ ] **AC-9** — Scrubber unit test (no kernel). Given an event carrying canaries in: message,
      formatted message, exception value (incl. a chained/previous exception), `extra`, `contexts`,
      `tags`, `transaction`, request URL `/reset-password/<token>` and `/verify-email/<token>`, a
      `Referer` header containing such a URL, query string, cookies, `Authorization`/`Cookie`/
      `Set-Cookie`/`X-Forwarded-For` headers, request body, the `user` object (email, username, IP),
      stack-frame `vars`, and a breadcrumb — the scrubbed event's JSON contains **none** of the
      canaries. Specifically: any e-mail address → `[email]`; any path segment after
      `/reset-password/` or `/verify-email/` → `[Filtered]`; any `extra`/`context` key matching
      `/pass|token|secret|sess|cookie|csrf|auth|email|dsn/i` → `[Filtered]`; `user` → removed;
      headers → allowlist only (`User-Agent`, `Accept`, `Accept-Language`, `Content-Type`, `Host`);
      query string, cookies, body, env, frame `vars`, breadcrumbs → removed.
- [ ] **AC-10** — `user_id` (a UUIDv7) **is retained** in `extra` — it is what makes the alert
      actionable and it is opaque outside our database. The scrubber's key denylist does not match it
      (asserted explicitly).
- [ ] **AC-11** — End-to-end: a functional test drives a real failure whose exception message
      contains the user's e-mail address (an SMTP-style `"550 <user@…>: Recipient address rejected"`
      thrown by a mailer double) through a real route, and the recorded event's JSON contains
      neither the address, nor any token, nor the session cookie value, nor the submitted password.
- [ ] **AC-12** — The Messenger exhausted-failure event of AC-4, built with an SMTP-style exception
      message containing a canary address, contains no address after scrubbing (the SMTP text
      reaches the event via `extra['monolog.context']['error']` and the exception value — the e-mail
      regex is what catches it; amended at implement, the message itself is not interpolated).

**Boot path & environments**

- [ ] **AC-13** — With `SENTRY_DSN` **unset** (not merely empty), every context that boots a kernel
      still boots and the bundle is disabled: `APP_ENV=prod php bin/console about` and
      `cache:clear --env=prod` exit 0 on a shell with the variable removed; `docker build --target
      production` succeeds with no `SENTRY_*` provided; CI is green with no `SENTRY_*` in the
      workflow; and with the box `.env` lacking the line, `app` and `messenger-worker` are both
      `running` (not `restarting`) 60 s after `up -d`.
- [ ] **AC-14** — The test environment cannot send: `.env.test` sets a DSN whose host ends in
      `.invalid` (RFC 2606, cannot resolve) **and** `when@test` sets `options.transport` to the
      recording transport. A test asserts both, so removing either fails the build.
- [ ] **AC-15** — `release` equals the image's git SHA: the production image built by `deploy.yml`
      has `SENTRY_RELEASE=${{ github.sha }}` (`docker run --rm --entrypoint printenv <image>
      SENTRY_RELEASE`), and an event from prod shows that release. `.env.example` contains **no**
      `SENTRY_RELEASE` line and says why (`env_file:` outranks the image's `ENV`). `environment`
      equals `APP_ENV` (`prod` on the box).
- [ ] **AC-16** — Sentry unreachable never fails the caller: `http_connect_timeout` ≤ 1 s and
      `http_timeout` ≤ 2 s (asserted from options). Manually: with `SENTRY_DSN` pointing at an
      unroutable host, `muzbar:ops:sentry-check` returns within 4 s and the app keeps serving.

**Operator proof**

- [ ] **AC-17** — `muzbar:ops:sentry-check` logs one `error` through the application logger (the
      real capture path, not `captureMessage()`), with a canary e-mail and a `token` context key,
      flushes the client, prints the event id, exits 0. With Sentry disabled it prints
      `Sentry is disabled (SENTRY_DSN is empty)` and exits 1. Functional test: enabled → exit 0 and
      exactly one recorded event with both canaries scrubbed; disabled → exit 1.
- [ ] **AC-18** — On the box, after deploy, the command run in **both** `app` and
      `messenger-worker` produces an event visible in the Sentry project within 60 s, showing
      `[email]`/`[Filtered]` in place of the canaries, `environment: prod` and the deployed SHA as
      release. Recorded (event ids) in the `/verify` notes.

**Architecture & docs**

- [ ] **AC-19** — No file under `src/src/Domain/` or `src/src/Application/` is added or changed
      (`git diff --stat main -- src/src/Domain src/src/Application` is empty). Deptrac green.
- [ ] **AC-20** — Docs updated: ADR-0013 (accepted), Constitution §3 gains an *Error tracking* row
      citing it, CLAUDE.md (status, a Sentry convention block, commands, the `SENTRY_RELEASE`
      footgun), infrastructure.md (§Monitoring rewritten + a *Sentry (runbook)* section: setup,
      EU region, DPA, alert rule, check command, exactly what data is sent), access-setup.md
      (`SENTRY_DSN` in the `.env` bootstrap), roadmap (carry-over item 2 closed with date), ADR-0010
      (dated note that its Sentry consequence is discharged and what remains: crash loops),
      FORboehpyk.md.
- [ ] **AC-21** — `make check` green; reviewer PASS.

## Failure contract

| # | Condition | Expected behaviour |
|---|---|---|
| 1 | `SENTRY_DSN` unset or empty (dev, CI, build, a box that forgot it) | Bundle disabled, kernel boots, nothing sent, no warning on every request. `sentry-check` exits 1 saying so. Never `EnvNotFoundException`. |
| 2 | `SENTRY_DSN` malformed | SDK throws at client build → kernel fails loudly on first use. Accepted: a typo in a secret should be loud; `sentry-check` is the post-edit step in the runbook. |
| 3 | sentry.io unreachable / slow | Send fails after ≤ 1 s connect / ≤ 2 s total; the SDK swallows the transport error; the request/command/message continues; the event is lost (no local queue). The record is still in the container's stderr via `nested`. |
| 4 | Sentry quota exhausted (429 from sentry.io) | Events dropped by Sentry; app unaffected. Spike protection on in project settings. Log lines unaffected. |
| 5 | Error storm (same error every request) | Sentry groups into one issue; quota is the ceiling. No in-app rate limit (non-goal). |
| 6 | Scrubber itself throws | Must not happen: the scrubber is total over the event shape and never throws on unexpected types (unit-tested with a minimal event). If it did, the SDK would drop the event, which is the safe direction. |
| 7 | Worker crash-loops before the kernel boots (e.g. missing required env var) | **Not reported.** No kernel, no logger, no Sentry. Detected only by the deploy's image check / `docker compose ps`. Stated in the runbook so nobody assumes coverage. |
| 8 | PHP fatal (OOM, max execution time) | Best effort: Symfony's `ErrorHandler` logs fatals at `critical` on the `php` channel when it can. Not guaranteed; not an AC. |
| 9 | 4xx (bot scanning, rate limit, CSRF failure) | Dropped by the scrubber; still in stderr logs (minus 404/405, as today). |
| 10 | Message retried, then succeeds | Zero events. |
| 11 | Message exhausts retries | Exactly one event; message in `failed` transport as today. |
