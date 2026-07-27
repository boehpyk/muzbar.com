# ADR-0010: Event delivery and transactional mail — sync dispatch, async send, no outbox yet

- **Status:** Accepted
- **Date:** 2026-07-26
- **Established by** the `identity-email-verification` slice, which makes the repository's first
  listener load-bearing.
- **Amends** [ADR-0008](./0008-domain-events-recorded-on-the-aggregate.md), whose Consequences section
  names this slice as the trigger to revisit synchronous dispatch. See the dated amendment at the foot
  of that ADR.

## Context

ADR-0008 §Consequences says, verbatim: *"The moment `identity-email-verification` makes a listener
load-bearing, this must be revisited… That slice must make the choice deliberately rather than
inheriting this one."*

It does. `IssueVerificationOnUserRegistered` listens to `UserRegistered` and issues the first
verification request; if it never runs, a user registers and no mail is ever sent.

Two orthogonal questions have to be answered **together**, because answering them separately produces
an incoherent design:

1. **Event publication.** Stay with synchronous PSR-14 dispatch after `flush()` — where a crash in the
   window between commit and dispatch loses the event — or adopt a transactional outbox, or move to
   Messenger with `DispatchAfterCurrentBusStamp`.
2. **Mail delivery.** Send synchronously inside the request, where an SMTP hiccup becomes a slow or
   failed signup for an account that already exists, or asynchronously via Messenger with retries and
   a failure transport — which buys reliability and costs a worker process, a restart policy and a
   monitoring story on a one-box budget.

## Decision

**1. Event publication stays synchronous PSR-14 dispatch after a successful save. No outbox.**
ADR-0008 decisions 1–7 are unchanged; only the "must be revisited" clause is discharged.

**2. Mail is sent asynchronously through Symfony Messenger.**
`Symfony\Component\Mailer\Messenger\SendEmailMessage` is routed to an `async` transport, with a
`failed` transport for exhausted retries.

**3. The reasoning, which is the part worth keeping: the two windows are not equally bad.**

A lost **mail** is invisible and permanent — nobody finds out, and the user simply never verifies. A
lost **event** here costs the user one click on the resend form, which this slice ships anyway. That
makes the resend endpoint the honest, cheap alternative to an outbox, and it should be written down as
the decision it is rather than discovered later by someone wondering why there is no outbox. It is
also why the resend form is **anonymous** and part of *this* slice rather than a follow-up: it is the
compensating action for the accepted gap.

**4. The transport is `doctrine://default`, not Redis.** Messenger's Redis transport requires
`ext-redis`, which this image does not have (slice 1 reaches Redis through `predis/predis`). Doctrine
is durable, already installed, and costs one extra migration (`messenger_messages`). Choosing it is a
constraint being respected, not a preference.

**5. Under `APP_ENV=test`, `SendEmailMessage` is routed to `sync`** so `MailerAssertionsTrait` can
assert on mail without a worker.

**6. The operational surface is accepted explicitly:** a `messenger-worker` Compose service running
`messenger:consume async --time-limit=3600 --memory-limit=128M` with `restart: unless-stopped`. The
time limit plus the restart policy is the standard way to survive slow leaks without adding a
supervisor.

## Alternatives

- **A transactional outbox now.** The genuinely reliable option: write the event to a table in the
  same transaction as the aggregate, relay it afterwards. Rejected as premature *for the event*, given
  decision 3's asymmetry — it costs a table, a relay process and its own monitoring to close a window
  whose consequence is one extra click on a form we are shipping regardless. Revisit when an event has
  a consequence the user cannot compensate for (a payment, a search alert).
- **Messenger with `DispatchAfterCurrentBusStamp` for events.** Would require putting commands on a
  bus first, which slice 1 deliberately did not do (`RegistrationController` calls the handler
  directly). Adopting a bus to fix an event-delivery window is a large change justified by a small
  one.
- **Synchronous mail.** Simplest, and it makes the SMTP relay a hard dependency of the signup path:
  a slow relay is a slow registration and an unreachable one is a failed one — for an account that
  *already exists*, since the user is committed before the mail is attempted. Rejected.
- **Redis transport for Messenger.** Would reuse the datastore already running. Blocked on `ext-redis`
  (decision 4). Revisit only if the extension is added for another reason.
- **Fire-and-forget mail in a `kernel.terminate` listener.** Cheap asynchrony with no worker, and no
  retry, no durability and no visibility when it fails. Rejected: that is the worst of both — the
  operational invisibility of async with none of the reliability.

## Consequences

- **Easy:** registration never 500s because of mail, and never waits on SMTP. Failed sends retry and
  then land somewhere inspectable instead of vanishing. Handlers and the Domain are untouched — the
  change is `messenger.yaml`, one Compose service and one migration.
- **Hard / watched:**
  - **A stopped worker looks exactly like a healthy system** to every automated check we currently
    have. `/health/ready` probes Postgres and Redis, not queue depth. Open question deferred to
    Phase 2: teach `/health/ready` to report a stale queue. The runbook line is written now.
  - **The failure transport is a queue nobody looks at** until someone is told to. It belongs in the
    infrastructure runbook, which is why this slice updates it.
  - **Sentry is now overdue.** The roadmap deferred it to "the first user-facing flow… when a silent
    500 starts costing a signup". That trigger fired in slice 1, and this slice adds an *asynchronous*
    failure path where a silent error is genuinely invisible. Still `devops` work, still not
    `Identity` work — and now the highest-value one outstanding.
  - **The commit→dispatch window remains open.** It is documented here as accepted, with the resend
    form as its compensating control, and `IssueVerificationOnUserRegistered` logs at error level when
    it fails so an operator sees it even before Sentry lands.
