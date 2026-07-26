# Muzbar Constitution

> The Constitution is the one document that **survives years**. Feature specs die per-feature; this
> does not. It changes only by an explicit, committed edit — never silently in chat. When code and
> Constitution disagree, one of them is a bug: fix the code, or amend this file on purpose.

**Project:** muzbar.com — Resonance Marketplace & Ecosystem Platform
**Owner:** solo developer (boehpyk)
**Status:** Living · established 2026-07-23
**Primary reference:** [muzbar-PRD.md](./muzbar-PRD.md)

---

## 1. Mission

A hyper-granular musical-instrument marketplace and local music-services directory. Buyers find
*ultra-specific* gear configurations through multi-parameter faceted search; sellers publish
schema-accurate listings in under three minutes; local studios and shops appear on a map-based
directory. The business exists to become **financially self-sufficient** — monthly recurring
revenue from premium listings and featured subscriptions must cover all infrastructure cost plus a
buffer.

The single sentence to keep in your head: **structured search over structured data, on one small box,
run by one person.**

## 2. Learning objective (first-class)

This project is also a vehicle to learn **Domain-Driven Design and Symfony in depth**. That is not a
footnote — it is a ranked goal. When two implementation paths are equally valid for the product but
one teaches DDD more honestly (a real aggregate boundary, a proper value object, a domain event
instead of a fat service), **choose the one that teaches**. The tooling in this repo is built to
*coach* DDD, not just enforce it.

## 3. Locked technology stack

These are decided. Changing any row requires a new ADR that supersedes the relevant one.

| Concern | Choice | ADR |
|---|---|---|
| Language | PHP 8.4 | [0001](./adr/0001-framework-symfony.md) |
| Framework | Symfony 7 | [0001](./adr/0001-framework-symfony.md) |
| Templating / UI | Twig + Symfony UX (Live Components, Stimulus, Turbo) | [0001](./adr/0001-framework-symfony.md) |
| Styling — admin UI | Tailwind CSS (standalone CLI, no Node) | [0006](./adr/0006-dual-styling-admin-public.md) |
| Styling — public UI | Hand-authored SCSS (standalone Dart Sass, no Node) | [0006](./adr/0006-dual-styling-admin-public.md) |
| Asset pipeline | Symfony AssetMapper (Node-free) | [0006](./adr/0006-dual-styling-admin-public.md) |
| ORM / migrations | Doctrine ORM + Doctrine Migrations | [0001](./adr/0001-framework-symfony.md) |
| Aggregate persistence | XML mapping in `Infrastructure`, DBAL types for VOs, app-assigned UUIDv7 | [0007](./adr/0007-persistence-conventions-for-domain-aggregates.md) |
| Domain events | Recorded on the aggregate, released by the handler, dispatched via a port | [0008](./adr/0008-domain-events-recorded-on-the-aggregate.md) |
| Database | PostgreSQL 16 | [0004](./adr/0004-dynamic-category-attribute-schema.md) |
| Search | **PostgreSQL** (composite indexes + FTS/pg_trgm) behind a `SearchPort` | [0002](./adr/0002-search-postgres-behind-port.md) |
| Cache / sessions | Redis 7 | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Async / queues | Symfony Messenger (Redis transport) | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Scheduling | Symfony Scheduler (30-day ad lifecycle) | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Auth | Symfony Security — email/password (verified) + OAuth2 (Google) + API-key authenticator | [0005](./adr/0005-auth-oauth2-google-plus-api-keys.md) |
| Payments | Stripe (webhooks) behind a `PaymentPort` | — |
| Transactional email | Symfony Mailer + Postmark/SendGrid transport; Mailpit in dev | — |
| Maps | Leaflet + OpenStreetMap (Google Maps optional) behind a `MapPort` | — |
| Reverse proxy / TLS | Traefik (shared, external network) → Nginx per app | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Hosting | Single VDS, Docker Compose | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Analytics | Umami — **shared instance already running on the VDS** (no muzbar container) | [0003](./adr/0003-infra-single-vds-compose-traefik.md) |
| Static analysis | PHPStan (max) + phpstan-symfony/doctrine | — |
| Layer enforcement | Deptrac | — |
| Style | php-cs-fixer (`@Symfony` + risky) | — |
| Tests | PHPUnit + Foundry fixtures + DAMA transactional bundle | — |

## 4. Architecture principles

1. **DDD, hexagonal (Ports & Adapters).** Three layers, strictly ordered dependencies:

   | Layer | May depend on | Must NOT import |
   |---|---|---|
   | `Domain` | nothing external | Symfony, Doctrine, any framework/vendor |
   | `Application` | `Domain` | `Infrastructure`, Doctrine, controllers |
   | `Infrastructure` | `Domain` + `Application` + Symfony/Doctrine | — |

   Enforced by **Deptrac** in CI, not by good intentions.

2. **The Domain is pure PHP.** Entities, aggregates, value objects, and domain events have zero
   `use Symfony\...` / `use Doctrine\...`. Doctrine mapping lives in XML/attributes *outside* the
   entity where practical, or is treated as an infrastructure concern — the entity models the
   business, not the table.

3. **Ports keep vendors swappable.** Every external dependency crosses a Domain-defined interface:
   `SearchPort`, `PaymentPort`, `MailerPort`, `MapPort`, and one repository port per aggregate. The
   concrete adapter (Doctrine, Stripe, Postmark, Leaflet) lives in `Infrastructure` and is wired in
   DI config. This is *why* we could pick Postgres for search today and swap in Meilisearch later
   with one binding change — see ADR-0002.

4. **Bounded contexts** (initial map — refine as the model sharpens):

   | Context | Owns | Key aggregate(s) |
   |---|---|---|
   | `Catalog` | the dynamic category/attribute schema | `Category`, `Attribute` |
   | `Listing` | gear advertisements + 30-day lifecycle | `Listing` |
   | `Directory` | service providers, studios, shops, geo | `ServiceProvider` |
   | `Identity` | users, roles, OAuth, API keys | `User`, `ApiKey` |
   | `Billing` | featured flags, Stripe subscriptions | `Subscription` |
   | `Notification` | saved searches + lifecycle emails | `SearchAlert` |
   | `Search` | the read model / faceted query port | (no aggregate — a port) |

   Ubiquitous language: keep a glossary in each context's spec. A "listing" is never a "product" and
   never an "ad" in code — pick the word and hold the line.

5. **The database is the source of truth. The search index (today: Postgres itself) is derived.**
   Read and write paths must mangle text identically (same stemmer, `unaccent` on both sides) or
   search silently returns nothing — a failure mode we have seen before and test against.

## 5. Non-goals (hold the line against scope creep)

- **No** in-platform payments/escrow for peer-to-peer sales at launch — external contact only.
- **No** native mobile apps — responsive web only.
- **No** Meilisearch/Elasticsearch until Postgres demonstrably cannot meet the latency budget on
  real data (ADR-0002).
- **No** microservices, Kubernetes, or multi-node anything. One VDS. One Compose file.
- **No** speculative multi-agent orchestration. Lean SDD (see [sdlc.md](./sdlc.md)).

## 6. Quality gates (Definition of Done for every feature)

A change is **done** only when all of these are green:

1. `php-cs-fixer` — clean.
2. `phpstan` at **max** — zero errors.
3. `deptrac` — zero layer violations.
4. `phpunit` — all pass; new behaviour has Domain unit tests + at least one Application/Feature test.
5. **Reviewer agent** returns PASS (zero CRITICAL, zero MAJOR) — see [tooling.md](./tooling.md).
6. The feature spec's enumerated acceptance criteria are all checked off.
7. Docs updated where behaviour changed (CLAUDE.md, relevant ADR, [FORboehpyk.md](../FORboehpyk.md)).

## 7. Performance & operational budgets

| Budget | Target | Source |
|---|---|---|
| Faceted search latency | < 200 ms @ 50 concurrent, 10k+ items | PRD §8 |
| Time-to-publish (returning user) | < 3 min average | PRD §8 |
| Infra cost coverage | 100% + 20% buffer from MRR within 6 months | PRD §8 |
| Uptime signal | health check probes Postgres + Redis, separates liveness/readiness | wiki: useful-php-health-check |

## 8. Security & privacy principles

- **Email isolation is a hard rule.** Public interfaces never expose raw user emails — only
  proxy mail-relay forms or explicitly chosen external channels (phone, Telegram). GDPR applies
  (EU users; Gothenburg is the narrative home).
- **Defense in depth on the box.** Docker bypasses UFW by writing iptables directly — a `ports:`
  mapping is a firewall hole. Admin UIs and datastores bind to `127.0.0.1` and are reached over SSH.
  Redis always has a password. (See [infrastructure.md](./infrastructure.md).)
- **Untrusted input crosses a validation boundary** before touching the Domain. The search-query
  sanitizer is the single most security-critical class — it strips every tsquery operator.
- **Anti-abuse** on the notification engine: rate-limit alert creation and email dispatch; route
  through an external SMTP relay to protect the VDS IP reputation.

## 9. How this Constitution is used

- The **`/plan`** command reads this file first; a feature spec may not contradict it.
- The **reviewer agent** treats §4, §6, and §8 as non-negotiable rules.
- Amendments are commits to this file with a message explaining *why*, ideally paired with an ADR.
