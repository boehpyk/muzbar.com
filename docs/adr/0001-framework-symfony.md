# ADR-0001: Symfony 7 + Twig + Doctrine as the application stack

- **Status:** Accepted
- **Date:** 2026-07-23

## Context

muzbar is a CRUD-heavy marketplace with an admin-managed dynamic schema, a morphing single-page
listing wizard, faceted search, a map directory, scheduled ad-lifecycle jobs, and email alerts. The
owner runs a mature **Laravel 13 + Livewire 4** project (samolit.com) on the same VDS and could
nearly clone it. That option was explicitly considered and **rejected in favour of Symfony** for one
overriding reason: **this project is a vehicle to learn Domain-Driven Design and Symfony in depth.**
The product could ship on either stack; the learning goal is served better by Symfony's explicit,
component-oriented model.

## Decision

Build on **PHP 8.4 · Symfony 7**, with:

- **Twig** + **Symfony UX** (Live Components, Stimulus, Turbo) for the UI. Live Components are the
  Symfony-native analog to Livewire and are the right tool for the morphing listing wizard (the form
  reshapes as the category changes without a full reload and without losing entered data).
- **Doctrine ORM + Doctrine Migrations** for persistence. Doctrine's `EntityRepository` and mapping
  metadata fit the Ports & Adapters model cleanly: interfaces (ports) in `Domain`, Doctrine
  repositories (adapters) in `Infrastructure`.
- **Symfony Messenger** for async work (email dispatch, index maintenance) and **Symfony Scheduler**
  for the 30-day ad lifecycle — replacing Laravel's queue/scheduler.
- **Styling** via Symfony AssetMapper (Node-free). Split by audience — Tailwind for the admin UI,
  hand-authored SCSS for the public UI — refined in [ADR-0006](./0006-dual-styling-admin-public.md).

## Alternatives

- **Laravel 13 + Livewire 4 (clone samolit):** fastest path, maximal reuse, owner's daily muscle
  memory. Rejected because it does not advance the DDD/Symfony learning objective. Its conventions
  are still mined as a *blueprint* (Docker topology, Makefile, git hooks, Umami, Mailpit).
- **Laravel + Inertia + Vue:** more frontend complexity, two languages, no learning payoff here.
- **API Platform on Symfony:** attractive for the commercial API-sync feature, but it pulls toward a
  resource-centric model that can fight explicit DDD aggregates. Revisit for Phase 3's API only,
  behind the same ports.

## Consequences

- **Easy:** honest DDD (aggregates, value objects, domain events, ports), explicit wiring that makes
  the architecture legible, first-class async/scheduling.
- **Hard / watched:** the owner rebuilds tooling (Makefile, hooks, CI) for Symfony instead of reusing
  samolit's; Doctrine has its own footguns (identity map, hydration memory, `IMMUTABLE` on generated
  columns — all documented in the owner's wiki) that must be respected. Slower initial CRUD velocity
  than Livewire — accepted as tuition.
- Keeping Doctrine out of the Domain requires discipline; Deptrac enforces it (Constitution §4).
