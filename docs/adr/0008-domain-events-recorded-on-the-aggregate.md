# ADR-0008: Domain events — recorded on the aggregate, released by the handler

- **Status:** Accepted
- **Date:** 2026-07-26
- **Established by** the `identity-user-password-auth` slice, which raised the repository's first two
  domain events (`UserRegistered`, `UserEmailVerified`).

## Context

`identity-user-password-auth` needed `UserRegistered` to exist *before* anything listens to it: the
next slice, `identity-email-verification`, hangs the verification email off it, and designing an event
after its first listener exists is how you end up with a listener-shaped event instead of a fact.

So the question was not *whether* to have domain events but where they live in the flow. Three
sub-questions had to be answered together, because answering them separately produces an incoherent
design: who creates an event, who publishes it, and when relative to the database transaction.

The constraint that shapes all three is Constitution §4.2 again. The Domain may not import Symfony, so
it cannot reference `EventDispatcherInterface`, and an aggregate certainly cannot be handed a
dispatcher to call.

## Decision

**1. The aggregate records; it never dispatches.** `Domain/Shared/Event/RecordsEvents` gives an
aggregate root a private buffer plus `recordThat()`. A state-changing method appends the fact it just
made true. The aggregate has no dispatcher, knows no transport, and cannot publish anything.

**2. The Application handler releases and publishes, after a successful save.**

```php
$this->users->save($user);
$this->events->dispatch(...$user->releaseEvents());
```

**3. `releaseEvents()` empties the buffer,** and that is the point rather than a tidy-up: an aggregate
saved twice in one request would otherwise replay its whole history on every release, and listeners
would see the same fact more than once. Releasing is destructive by design; a second call legitimately
returns `[]`.

**4. Publication goes through a Domain port,** `Domain/Shared/Port/DomainEventDispatcher`, with a
variadic `dispatch(DomainEvent ...$events)`. Variadic so a no-op operation that recorded nothing needs
no guard at the call site.

**5. The adapter forwards to Symfony's PSR-14 dispatcher, synchronously.** The event object *is* the
Symfony event and its FQCN *is* the event name, so a listener subscribes to
`App\Domain\Identity\Event\UserRegistered` directly with no mapping table.

**6. Events carry value objects, never the aggregate.** An event must be a self-contained fact; handing
a mutable aggregate to a listener is how aggregates get corrupted from outside.

**7. Idempotency lives in the aggregate, not the handler.** `User::verifyEmail()` returns early when
already verified and records nothing, so `VerifyUserEmailHandler` has no branch at all. Every present
and future adapter over that handler inherits the guarantee rather than re-implementing it.

## Alternatives

- **Aggregate calls a dispatcher directly.** Impossible without importing a framework into `Domain`,
  and wrong anyway: it publishes a fact that a later failure in the same transaction could still roll
  back.
- **Doctrine lifecycle events / `postFlush` listeners.** Automatic, and technically after-commit. But
  it moves the decision "when is this fact public" out of the use case and into ORM configuration,
  which is invisible at the call site and impossible to unit-test without a database.
- **Dispatch *before* save.** Simpler to write, and wrong: a listener would observe registrations that
  never happened when the flush fails.
- **Messenger with `DispatchAfterCurrentBusStamp` now.** The right eventual answer for asynchronous
  work, but it needs a transport, a worker, a supervisor unit and a failure queue — real operational
  surface on a one-box budget, bought before anything asynchronous exists. The port means adopting it
  later changes one adapter and no handler.
- **A transactional outbox now.** The genuinely reliable option (see Consequences), rejected as
  premature for the same reason: it costs a table, a relay process and its own monitoring while zero
  listeners exist.

## Consequences

- **Easy:** aggregates stay pure and unit-testable — asserting "registering records exactly one
  `UserRegistered`" needs no kernel and no database. Handlers are provable with a spy dispatcher. The
  transport is swappable without touching Domain or Application. Listeners subscribe by class name.
- **Hard / watched:**
  - **Dispatch sits outside the transaction.** A crash between `flush()` and `dispatch()` loses the
    event silently. This is knowingly accepted *only* while nothing listens. **The moment
    `identity-email-verification` makes a listener load-bearing, this must be revisited** — a
    transactional outbox, or Messenger with `DispatchAfterCurrentBusStamp`. That slice must make the
    choice deliberately rather than inheriting this one.
  - **Synchronous dispatch means a slow listener is a slow request,** and a throwing listener surfaces
    after the data is already committed. Fine for zero listeners; the first listener that does I/O
    (sending mail) is the trigger to go asynchronous.
  - **Nothing forces a handler to release the buffer.** Forgetting is silent — the events simply never
    fire. This bit the test suite already: Foundry's factory persists a `User` directly, bypassing the
    handler, so the fixture's `UserRegistered` sat unreleased and bled into a later test's event count.
    The factory now releases in `afterInstantiate()`. Worth remembering that any code path which
    persists an aggregate *without* going through a handler has the same hole.
