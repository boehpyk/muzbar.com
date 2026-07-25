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
>    **Still outstanding after slice 1.** Partially mitigated: `deptrac.yaml` now declares
>    `SymfonyVendor` / `DoctrineVendor` layers, so a stray framework import in `Domain/` fails
>    `make check` (proven, not assumed). The hook would still catch it earlier — at write time rather
>    than at commit time.
> 2. Sentry, deliberately deferred to the first Identity slice (see above).
>    **Now due.** That trigger condition — "the first user-facing flow, which is when a silent 500
>    starts costing a signup" — was met on 2026-07-26 when `/register` and `/login` shipped.

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
  - [ ] `identity-email-verification` — `symfonycasts/verify-email-bundle`, the "usable account"
        invariant (verified email **or** a linked verified OAuth identity). **Needs no migration on
        `identity_user`** — `email_verified_at` already ships. Its job is to add the
        `VerifiedAccountUserChecker` + one `security.yaml` line (inverting AC-24 of the previous
        slice), a second adapter over the existing `VerifyUserEmailHandler`, and a deliberate decision
        about event delivery once a listener on `UserRegistered` becomes load-bearing (ADR-0008).
  - [ ] `identity-password-reset` — `symfonycasts/reset-password-bundle` flow.
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
