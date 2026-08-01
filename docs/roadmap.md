# Muzbar Roadmap — Phased, Gated Build

The PRD's three build phases, decomposed into step-by-step milestones for solo, spec-driven work.
Each phase **ends in a validation gate** tied to a PRD success metric or suggested validation — you do
not enter the next phase until its gate is green. Gates are where a solo dev catches a wrong
foundation before building three phases on top of it.

Legend: each milestone is a `/plan → /implement → /verify` cycle (see [sdlc.md](./sdlc.md)).

---

## Phase 0 — Foundation & scaffolding *(new; precedes PRD Phase 1)*

Stand up the skeleton the SDLC assumes. No product features yet.

- [x] Symfony 7 app under `src/`, hexagonal folders (`Domain/`, `Application/`, `Infrastructure/`),
      Deptrac config expressing the layer rules.
- [x] Docker: `docker-compose.yml` (prod) + `docker-compose.dev.yml` (dev), PHP 8.4-FPM multi-stage
      Dockerfile, nginx, postgres, redis — footgun guards applied (ADR-0003 / infrastructure.md).
- [x] Quality tooling: php-cs-fixer, PHPStan max (+ symfony/doctrine extensions), PHPUnit + Foundry +
      DAMA, Makefile (`up.dev`, `console`, `migrate`, `cs`, `stan`, `deptrac`, `test`, `check`,
      `db.dump`), tracked git hooks (`scripts/git-hooks`, `core.hooksPath`).
- [x] Test environment: `APP_ENV=test`, dedicated `muzbar_test` DB (never the dev DB), `.env.test`,
      DAMA transactional rollback; `make test` provisions/migrates it against the test env.
      Isolation comes from `when@test: dbal.dbname_suffix` in `config/packages/doctrine.yaml` — **not**
      from a `DATABASE_URL` in `.env.test`, which would defeat it (see the note in that file).
- [ ] `.claude/` agents, commands, hooks, skills per [tooling.md](./tooling.md).
      *Partial:* 6 agents, 5 commands, 3 skills are in place; the Claude Code hooks
      (`pre-write-guard`, `post-implement-check`) are **not** configured in `.claude/settings*.json`.
      (The *git* hooks — `pre-commit`, `commit-msg` — are done, under Quality tooling above.)
- [x] GitHub Actions CI (lint + stan + deptrac + test) + deploy workflow ([cicd.md](./cicd.md)).
- [x] Health endpoints (`/health/live`, `/health/ready`) — `/health/ready` probes Postgres + Redis and
      returns 503 when either is down (never a bare `return 'ok'`).
- [x] Umami analytics — muzbar is registered as a site on the shared VDS instance (no container to
      deploy). The `<script>` snippet itself still has nowhere to live: `templates/base.html.twig` is
      the untouched Symfony skeleton default and nothing user-facing renders yet. It lands with the
      first public layout, taking the site ID / script URL as config (infrastructure.md §Analytics).
- [ ] Sentry error tracking — **deferred by decision (2026-07-25)**, not an oversight.
      infrastructure.md calls it "Phase 0/1, cheap insurance for a solo dev"; the natural moment is the
      first user-facing flow (the Identity slices), which is when a silent 500 starts costing a signup.
- **Gate — MET** (merged in #1, hardened by #3–#6, 2026-07-23 → 07-24): CI green, `make check` passes,
  the VDS deploy succeeds and `https://muzbar.com/health/ready` returns 200 with Postgres + Redis
  probed. *Caveat:* the "hello" slice is `HealthController` — an Infrastructure endpoint, not a Domain
  slice; `Domain/Shared/` is still empty. The first real Domain code lands in Phase 1.

> **Phase 0 carry-over into Phase 1** (neither blocks the gate, but neither may be forgotten):
>
> 1. The two Claude Code hooks from [tooling.md](./tooling.md) — `pre-write-guard` (Domain purity) and
>    `post-implement-check`. Worth wiring *before* the Identity slices: `pre-write-guard` is exactly
>    the guard that catches a stray `use Symfony\...` in the `User` aggregate.
>    **Still outstanding after slice 1.** Largely mitigated: `deptrac.yaml` declares a catch-all
>    `Vendor` layer (anything outside `App\`, excluding PHP built-ins) and enables the `use` emitter,
>    and `make deptrac` runs `--fail-on-uncovered` — so *any* stray vendor import in `Domain/` fails
>    `make check`, even an unused one (proven, not assumed). The hook would still catch it earlier — at
>    write time rather than at commit time.
> 2. Sentry, deliberately deferred to the first Identity slice (see above).
>    **Now due.** That trigger condition — "the first user-facing flow, which is when a silent 500
>    starts costing a signup" — was met on 2026-07-26 when `/register` and `/login` shipped.
>    **Overdue after slice 2 (2026-07-28), and the argument has changed shape.** Until now the worst
>    case was a 500 somebody eventually notices. `identity-email-verification` adds an *asynchronous*
>    failure path: `IssueVerificationOnUserRegistered` deliberately swallows its own exceptions so a
>    dead mail relay cannot 500 a committed registration, and the Messenger worker's failure
>    transport is a queue nobody looks at. Both are correct designs and both are silent by
>    construction. The log lines exist; nothing reads them. This is now the highest-value
>    `devops` item outstanding — see [ADR-0010](./adr/0010-event-delivery-and-transactional-mail.md).
>    Related and cheaper: `/health/ready` still probes only Postgres and Redis, so **a stopped
>    messenger worker looks exactly like a healthy system.**
>    **Overdue after slice 4 (2026-08-01), and for the first time on work whose *only* symptom of
>    failure is the absence of a symptom.** `identity-challenge-pruning` ships a backlog, a heartbeat
>    and a log line on every run, so the failure is now genuinely *visible* — and nothing yet
>    *notices* it. That is the whole remaining gap, and it is Sentry's.
> 3. **`deploy.yml` never restarts `messenger-worker`.** The deploy runs `docker compose pull app`
>    and `docker compose up -d app nginx`; the worker is in neither list, so it runs whatever image it
>    last had, indefinitely, and the only symptom is behaviour that does not match the source. Found
>    2026-08-01 while costing slice 4's scheduling decision — **a pre-existing bug, not one that slice
>    introduced.** It is also the strongest single argument against adding any second long-running
>    container: a scheduler silently running an *old retention policy* would delete data by a rule
>    nobody currently believes is in force. **Fix this before any future slice adds a `scheduler`
>    service.** Manual workaround in `docs/infrastructure.md`.
> 4. **`messenger_messages` and the `failed` queue still grow without bound.** They are Messenger's
>    tables with Messenger's semantics, and `identity-challenge-pruning` deliberately did not acquire
>    them — a job named for `Identity` challenges must not quietly become the system's general
>    garbage collector. Named here so nobody assumes slice 4 covered it. The pruning command is a
>    working pattern to copy, not to extend.

## Phase 1 — Data model, dynamic schema & auth *(PRD Phase 1)*

- [ ] `Identity` context: `User` aggregate, roles, three authenticators (ADR-0005) — email/password
      with **email verification + password reset + login throttling**, Google OAuth, and account
      linking; login/register overlay + intended-action redirect. **Too big for one cycle — sliced:**
  - [x] `identity-user-password-auth` — **DONE 2026-07-26.** `User` aggregate, `Email` /
        `HashedPassword` / `PlainPassword` / `UserId` VOs, `Role` enum, `UserRegistered` /
        `UserEmailVerified` events, `UserRepository` + `PasswordHasher` ports, the first migration
        (`identity_user`), register + form login + logout + `/account`, `login_throttling` on Redis,
        and `muzbar:identity:verify-email`. Established [ADR-0007](./adr/0007-persistence-conventions-for-domain-aggregates.md)
        (persistence conventions) and [ADR-0008](./adr/0008-domain-events-recorded-on-the-aggregate.md)
        (domain events), and amended ADR-0005 to record that the usable-account invariant is modelled
        now and enforced in the next slice.
  - [x] `identity-email-verification` — **DONE 2026-07-28.** The context's **second aggregate**,
        `EmailVerificationRequest`, with `VerificationToken` / `HashedVerificationToken` /
        `EmailVerificationRequestId` VOs, the `EmailVerificationRequested` event, three ports
        (repository, token generator, mailer), a second additive migration, and the verify / sent /
        resend routes with their mail templates. `VerifiedAccountUserChecker` + one `security.yaml`
        line invert the previous slice's AC-24, and **`Domain/Identity/Entity/User.php` is untouched
        in the whole diff** — which was the point.
        Established [ADR-0009](./adr/0009-email-verification-tokens-modelled-in-the-domain.md)
        (the token is domain-modelled, and **`symfonycasts/verify-email-bundle` is not installed**:
        it is stateless, so single use, revocation and auditability are not expressible with it —
        ADR-0005 amended accordingly) and
        [ADR-0010](./adr/0010-event-delivery-and-transactional-mail.md) (sync event dispatch, async
        mail over Messenger + a worker container, no outbox — discharging ADR-0008's watched clause).
        **Owed by this slice:** a pruning job for expired/redeemed requests, and the orphan-row
        question when GDPR erasure is designed (there is deliberately no FK to `identity_user`).
  - [x] `identity-password-reset` — **DONE 2026-07-29.** The context's **third aggregate**,
        `PasswordResetRequest`, with `ResetToken` / `HashedResetToken` / `PasswordResetRequestId`
        VOs, the `PasswordResetRequested` and `UserPasswordChanged` events, three more ports
        (repository, token generator, mailer), a third additive migration, and the
        forgot-password / sent / link-check / reset routes with their mail templates.
        **`symfonycasts/reset-password-bundle` is not installed**
        ([ADR-0011](./adr/0011-password-reset-challenges-modelled-in-the-domain.md), which also
        amends ADR-0005 and ADR-0009). *This line previously named the bundle. It was written in
        Phase 0 as part of a stack survey, and it is corrected rather than quietly outvoted — a
        roadmap line that survives contradicting an accepted ADR is how documentation starts lying.*
        The slice is the **same shape as slice 2 with four rules inverted** — a replay is refused,
        reissuing invalidates outstanding links, the GET mutates nothing, and the two saves go in
        the opposite order — and each inversion carries a call-site comment saying *why*, because
        two files that differ four times without stated reasons is how one of them eventually gets
        "fixed". `User` **is** touched, unlike slice 2: one property, one method, one reader, one
        import, and no more (AC-39). A successful reset also verifies the email, which is what stops
        an unverified account being stranded with no recovery path at all.
        **Owed by this slice:** nothing new — it *adds to* the pruning debt rather than paying it,
        which is why `identity-challenge-pruning` is scheduled next.
  - [x] `identity-challenge-pruning` — **DONE 2026-08-01;
        [ADR-0012](./adr/0012-challenge-retention-and-recurring-background-work.md) accepted.** A
        `muzbar:identity:prune-challenges` console command under **host cron** (hourly), sweeping
        **overdue** rows from both challenge tables (`identity_email_verification_request`,
        `identity_password_reset_request`), plus the orphan-row answer that ADR-0009 decision 4 left
        for GDPR erasure. Scheduled here, before OAuth, because two tables is where this debt stops
        being a footnote.
        *This line previously read "one **Scheduler** task sweeping **expired/redeemed/invalidated**
        rows". Both halves are corrected rather than quietly outvoted, on slice 3's precedent — a
        roadmap line that survives contradicting an accepted ADR is how documentation starts lying.*
        **(a)** `symfony/scheduler` is not installed: it would add a bundle to every kernel that
        boots plus a **second daemon on a system that cannot see the first one**, and the deploy
        pipeline does not currently restart the daemon it already has (see `devops` below).
        Constitution §3's Scheduling row is read as **scoped** by its own `(30-day ad lifecycle)`
        parenthetical — this slice defers to that slice rather than picking a rival technology.
        **(b)** the predicate is **`expires_at` + a per-table retention window (7 days verification,
        30 days reset)** and deliberately never consults `redeemed_at` or `invalidated_at`: the two
        tables' notions of "dead" are inverted by design (ADR-0011 decision 9), so a "dead" predicate
        would be the rejected shared base class re-derived **in SQL**, where no unit test can reach
        it. `expires_at` is a ceiling on every reason a row is finished, so the sweep gets the same
        rows without encoding the disagreement.
        **The observability is the point, not a trimming:** this is the first recurring process here
        whose only failure symptom is silence, so it ships a backlog count that **cannot be faked by
        a job that runs and does nothing**, a Redis heartbeat, and a log line on every run including
        the all-zero ones — rather than adding a fourth "Sentry is overdue" to the risk list.
        **What it actually shipped**, beyond the plan: the first `DELETE` in the repository's
        history, licensed by a rule worth keeping — *an aggregate governs its state transitions, not
        its own non-existence; put in the Domain the part that can be wrong.* The Domain diff is one
        constant and one static per aggregate and nothing else. Two findings came out of it that the
        plan did not predict. **(i)** A dedicated Monolog channel is *not* enough on its own: prod's
        `fingers_crossed` handler buffers an INFO record and discards it, so the AC-20 line would have
        been silently dropped in the one environment it exists for. Verified by removing the
        dedicated handler and watching a green run write **zero bytes**. **(ii)** PHP will not compile
        one test double implementing both repository ports — `nextIdentity()` is covariant and a union
        of the two id types is wider than either — so the type system reaches AC-32's "no shared
        abstraction" conclusion unaided. **Owed by this slice:** nothing. It pays the debt slices 2
        and 3 both recorded, and hands GDPR erasure a written specification rather than a hope.
  - [ ] `identity-password-changed-notification` — a listener on `UserPasswordChanged` mailing "your
        password was changed on X". ~40 lines now that the event exists; it is the mechanism by which
        an account-takeover victim finds out.
  - [ ] `identity-google-oauth` — `knpuniversity/oauth2-client-bundle`, `OAuthIdentity` VO, account
        linking by email (never a duplicate `User`).
  - [ ] `identity-login-overlay` — login/register overlay Live Component + intended-action redirect
        preserved across both the form and the OAuth round-trip.
- [ ] `Catalog` context: `Category` + `Attribute` + `AttributeOption` aggregates with invariants;
      admin CRUD for categories and attribute schemas (FR-1). This is the core DDD exercise.
- [ ] `Listing` context (skeleton): `Listing` aggregate + `listing_attribute_value` model and the
      hot-column promotion mechanism (ADR-0004). No search/wizard UI yet.
- [ ] API-key issuance (hashed, revocable) — model only; endpoints in Phase 3.
- **Gate — Schema-Mutation PoC** (PRD validation #2): add a new attribute (e.g. "Fretboard Wood") via
      the admin UI and confirm it propagates to the dynamic form-render path and to a facet query
      **without corrupting existing listing rows**. Restore-from-backup rehearsed once.

## Phase 2 — Faceted search & listing marketplace *(PRD Phase 2)*

- [ ] `Search` context: `SearchPort` + `FacetedQuery` value object + Postgres adapter (composite
      indexes, facet counts); FTS + pg_trgm on title/description with identical write/read mangling +
      tsquery sanitizer (ADR-0002).
- [ ] Morphing single-page listing wizard as a **Symfony UX Live Component** — selecting a category
      reshapes the lower form (parametric dropdowns) without a reload or data loss (US-1, UX §).
- [ ] Public faceted search UI with dynamic, category-driven facets (US-2, FR-3).
- [ ] 30-day ad lifecycle: Symfony Scheduler expiry + hide, 3-day-prior warning email with 1-click
      renewal link (Messenger + Mailer). Mailpit in dev.
- [ ] "Subscribe to this Search": capture facet params + verified email on a 0-result page; the
      `Notification` context evaluates new listings against active alerts and fires a match email
      (US-5, FR-6).
- **Gate — Search Latency Benchmark** (PRD metric + validation #1): seed 10k+ mock listings; prove
      **< 200 ms @ 50 concurrent** with `EXPLAIN` confirming every facet hits an index. **Gate —
      Spam-Filter Clearance** (validation #3): renewal/alert emails clear major inbox filters via the
      external SMTP relay.

## Phase 3 — Directory, monetization & API sync *(PRD Phase 3)*

- [ ] `Directory` context: `ServiceProvider` aggregate with coordinates, equipment lists, room specs;
      Leaflet/OSM map with responsive, swipeable cards centering on the selected pin (US-3, FR-4).
- [ ] `Billing` context: Stripe subscriptions + webhook processing toggling the "Featured" flag on
      listings/profiles on verified payment (US-4, FR-5) — behind `PaymentPort`.
- [ ] Commercial API: authenticated `/api/*` endpoints (API-key auth) for inventory sync (FR-2).
      Consider API Platform here, behind the same ports (ADR-0001).
- **Gate — Monetization loop end-to-end:** a business user purchases a featured tier via Stripe test
      mode, the webhook flips the flag, and the featured listing ranks first in local search. Alpha
      telemetry begins feeding the MRR-vs-cost and completion-rate baselines (PRD weak-spot #1).

---

## Cross-cutting, every phase

Security (email isolation, input validation, authz on mutations), accessibility/responsive UI, and
**FORboehpyk.md** kept current with the bugs hit and lessons learned. GDPR review before public
launch. Revisit Meilisearch **only** if the Phase 2 benchmark fails on real data.
