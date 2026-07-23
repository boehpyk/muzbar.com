---
name: ddd-feature
description: The canonical recipe for adding a Domain-Driven Design slice to muzbar — choosing the bounded context, defining the aggregate boundary, value objects, domain events, a port, an application handler, the Doctrine adapter, and the DI wiring. Use when building or reviewing any new feature that touches the domain.
---

# Adding a DDD feature to muzbar

Follow this order every time. It keeps the Domain pure (Deptrac-clean) and makes the design legible.

## 1. Choose the bounded context
Catalog · Listing · Directory · Identity · Billing · Notification · Search (Constitution §4). Put the
code under `src/src/Domain/<Context>/`, `Application/<Context>/`, `Infrastructure/<Context>/`. Use the
spec's ubiquitous language exactly.

## 2. Domain layer (`Domain/<Context>/`) — pure PHP, no framework
1. **Value objects** first (`ValueObject/`): immutable, validated in the constructor, compared by
   value. Reach for these instead of primitives (`Money`, `Slug`, `Coordinates`, `Email`).
2. **Aggregate root** (`Entity/`): holds invariants; mutate only through methods, never public
   setters. Keep the aggregate small — it is the consistency boundary.
3. **Domain events** (`Event/`): past-tense facts (`ListingPublished`) the aggregate records.
4. **Port** (`Port/`): the repository interface for the aggregate (`ListingRepository`) and any
   service port (`SearchPort`). Interfaces only, defined by the Domain.

## 3. Application layer (`Application/<Context>/`)
A **command/query + handler** per use case. The handler: load the aggregate via its port → call
aggregate methods → persist via the port. Thin, transactional, idempotent where retryable.

## 4. Infrastructure layer (`Infrastructure/<Context>/`)
1. **Adapter:** a Doctrine repository implementing the port, in `Persistence/`. Doctrine **mapping is
   here** (prefer XML) so the Domain entity carries no ORM attributes.
2. **Migration:** `make migration.make` → review → additive, backward-compatible.
3. **UI:** controller or Live Component; validate input at this boundary.
4. **DI wiring:** bind port → adapter in `config/services.yaml`. A port with no binding is a bug.

## 5. Prove it
`qa` writes Domain unit tests (invariants, VO validation, events) + Application/Feature tests (real
DB). Run `make check`. Deptrac must show 0 violations — that is the proof the Domain stayed pure.

## Common mistakes
- Anemic aggregate (logic in a service instead of the entity) → move rules into the aggregate.
- ORM attributes on a Domain entity → move mapping to Infrastructure (XML).
- Handler reaching into Doctrine → it must go through the port.
