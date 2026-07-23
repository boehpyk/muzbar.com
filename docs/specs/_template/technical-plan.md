# Technical Plan: <feature name>

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

## Domain layer (pure PHP)

- **Aggregate / entity changes:** <Aggregate, its invariants, what protects them>
- **Value objects:** <new/changed VOs and why they're VOs (immutability, equality, validation)>
- **Domain events:** <events raised, who reacts>
- **Ports (interfaces):** <RepositoryInterface / SearchPort / PaymentPort … defined in Domain>

## Application layer

- **Command/Query:** <name + fields (the input contract)>
- **Handler:** <use case flow, which ports it calls, transaction boundary>
- **Idempotency:** <if the operation can be retried, how duplicates are prevented>

## Infrastructure layer

- **Persistence:** <Doctrine mapping, adapter implementing the port, migrations>
- **HTTP / UI:** <controller or Live Component; routes; the morphing behaviour if a form>
- **Async / schedule:** <Messenger message + handler / Scheduler task>
- **External:** <Stripe / Mailer / Maps adapter behind its port>
- **DI wiring:** <port → adapter bindings, tagged services>

## Interface boundary & input contract

The exact surface the rest of the system sees, and what it accepts/rejects — so the implementation
doesn't invent it.

## Data & migrations

- <new tables/columns, indexes (name the composite index and the query it serves), backward-compat plan>

## Test plan

- **Domain unit:** <invariants, VO validation — no kernel>
- **Application/Feature:** <handler + adapter against real DB (DAMA rollback)>
- **Performance (if applicable):** <benchmark + EXPLAIN assertion>

## Risks / open questions

- <anything the reviewer or human should weigh in on>
