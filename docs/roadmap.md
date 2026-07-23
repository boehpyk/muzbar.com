# Muzbar Roadmap — Phased, Gated Build

The PRD's three build phases, decomposed into step-by-step milestones for solo, spec-driven work.
Each phase **ends in a validation gate** tied to a PRD success metric or suggested validation — you do
not enter the next phase until its gate is green. Gates are where a solo dev catches a wrong
foundation before building three phases on top of it.

Legend: each milestone is a `/plan → /implement → /verify` cycle (see [sdlc.md](./sdlc.md)).

---

## Phase 0 — Foundation & scaffolding *(new; precedes PRD Phase 1)*

Stand up the skeleton the SDLC assumes. No product features yet.

- [ ] Symfony 7 app under `src/`, hexagonal folders (`Domain/`, `Application/`, `Infrastructure/`),
      Deptrac config expressing the layer rules.
- [ ] Docker: `docker-compose.yml` (prod) + `docker-compose.dev.yml` (dev), PHP 8.4-FPM multi-stage
      Dockerfile, nginx, postgres, redis — footgun guards applied (ADR-0003 / infrastructure.md).
- [ ] Quality tooling: php-cs-fixer, PHPStan max (+ symfony/doctrine extensions), PHPUnit + Foundry +
      DAMA, Makefile (`up.dev`, `console`, `migrate`, `cs`, `stan`, `deptrac`, `test`, `check`,
      `db.dump`), tracked git hooks (`scripts/git-hooks`, `core.hooksPath`).
- [ ] Test environment: `APP_ENV=test`, dedicated `muzbar_test` DB (never the dev DB), `.env.test`,
      DAMA transactional rollback; `make test` provisions/migrates it against the test env.
- [ ] `.claude/` agents, commands, hooks, skills per [tooling.md](./tooling.md).
- [ ] GitHub Actions CI (lint + stan + deptrac + test) + deploy workflow ([cicd.md](./cicd.md)).
- [ ] Health endpoints (`/health/live`, `/health/ready`), Sentry, Umami tracking snippet wired to the
      shared VDS instance (register muzbar as a site there — no container to deploy).
- **Gate:** CI green on a trivial "hello" domain slice; `make check` passes; a deploy to the VDS
  succeeds and `/health/ready` returns 200 with Postgres + Redis probed.

## Phase 1 — Data model, dynamic schema & auth *(PRD Phase 1)*

- [ ] `Identity` context: `User` aggregate, roles, three authenticators (ADR-0005) — email/password
      with **email verification + password reset + login throttling**, Google OAuth, and account
      linking; login/register overlay + intended-action redirect.
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
