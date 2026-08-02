# ADR-0012: Challenge retention and recurring background work

- **Status:** Accepted
- **Date:** 2026-08-01
- **Established by** the `identity-challenge-pruning` slice — the repository's **first deletion of
  anything**, and its **first recurring background process**.
- **Amends** [ADR-0009](./0009-email-verification-tokens-modelled-in-the-domain.md) — decision 4's
  orphan-row clause is **discharged, not reversed**: no foreign key is added, and the orphan question
  is answered here. See the dated amendment at the foot of that ADR.
- **Amends** [ADR-0011](./0011-password-reset-challenges-modelled-in-the-domain.md) — its *"the
  pruning debt now spans two tables"* consequence is discharged. See the dated amendment there.
- **Applies** [ADR-0007](./0007-persistence-conventions-for-domain-aggregates.md) and
  [ADR-0008](./0008-domain-events-recorded-on-the-aggregate.md) unchanged, and adds **no** aggregate,
  event, value object or exception — see decision 4, where each absence is argued rather than left to
  be noticed.
- **Touches Constitution §3**, whose Scheduling row names Symfony Scheduler. Decision 5 states the
  reading under which this slice does not contradict it, in words, so that a future reader holding
  §3 in one hand and a crontab in the other finds an argument rather than a discrepancy.

## Context

Two tables have been accumulating rows since slice 2 and slice 3, and nothing has ever deleted from
either: `identity_email_verification_request` and `identity_password_reset_request`. Every "verify
your address" and every "reset your password" link ever issued left one behind. ADR-0009 recorded the
debt as *"a pruning job owed"*; ADR-0011 escalated it to *"two tables is where it stops being a
footnote"* and named this slice as the natural moment to also answer the orphan-row question ADR-0009
decision 4 left open. This is the slice that pays both.

**The debt is hygiene, not a live security exposure, and being precise about that changes the
design.** A stored row holds the **SHA-256 digest** of a token (ADR-0009 decision 2, ADR-0011
decision 2), not the token. A row that outlives its usefulness is not a credential an attacker can
use at any cost; it is the digest of a dead secret. What it *is* is `(user_id, issued_at)` — a dated
record that a named person asked to recover their account, which is personal data under GDPR
(Constitution §8) serving no purpose once the challenge is dead. That reframing is why the retention
windows below are measured in days rather than minutes, and why "keep them forever, they're tiny" is
declined even though the rows genuinely are tiny.

**Two forces make this slice harder than "write a DELETE".**

First, the two aggregates **disagree about what a finished row is, on purpose**. ADR-0011 decision 9
enumerated four deliberate inversions and concluded they must not share a base class. A pruning slice
arrives with that same shared abstraction ready-made, wearing a different hat: *"delete the dead rows
from both tables"* is the rejected base class expressed as a `WHERE` clause — and expressed in SQL,
where, as ADR-0011 decision 4 already argued about the bulk `UPDATE` it declined, **no unit test can
reach it.**

Second, this is the first thing in the system that **runs on a schedule with nobody waiting on its
output**, and therefore the first whose entire failure mode is silence. ADR-0010's amendment recorded
the neighbouring lesson empirically: *a stopped worker looks exactly like a healthy system.* A stopped
*pruner* is worse, because a stopped worker at least has a queue backing up behind it that somebody
eventually notices. Three consecutive slices have written "Sentry is overdue" into their risks and
shipped anyway; each of those ships work that fails loudly enough to be noticed without it. This one
does not, so it carries its own signal rather than adding a fourth IOU.

## Decision

**1. The prune predicate is `expires_at` plus a retention window, and neither table's notion of
"dead" is ever consulted.**

The predicate, entire:

```
expires_at < (now − RETENTION_AFTER_EXPIRY_SECONDS)
```

Strictly less than, per table, and nothing else. The strings `redeemed_at`, `invalidated_at`,
`email_verified_at` and `password_changed_at` appear in **no** pruning predicate in any layer.

The reason is that "dead" is not one concept here. Verified against the shipped mappings rather than
against memory:

| | `…_email_verification_request` | `…_password_reset_request` |
|---|---|---|
| Terminal columns | `redeemed_at` | `redeemed_at`, **`invalidated_at`** |
| Replay of a redeemed row | **absorbed** | **refused** |
| Reissue | leaves siblings alive | **invalidates siblings** |
| A cross-aggregate reason to be finished | inert once `identity_user.email_verified_at` is set | stale once `issued_at < identity_user.password_changed_at` |
| Lifetime | 86 400 s | 3 600 s |

The fourth row is the decisive one. **Both tables have a notion of "finished" that is not visible in
the row at all** — it needs the user's row — and the two notions are *different columns with
different comparisons*. A predicate that wanted to be complete would join `identity_user` twice, two
different ways, and would thereby encode two aggregates' cross-boundary rules in one Infrastructure
SQL statement.

Every one of those reasons is **bounded above by the same thing**. A redeemed row expires. An
invalidated row expires. A stale row expires. An inert row expires. `expires_at` is a *ceiling* on
all of them, and it is the one column both tables define identically
(`TIMESTAMP(0) WITH TIME ZONE NOT NULL`), derived identically and un-overridably by both aggregates
inside `issue()` (invariants I-8 and I-15), and **already stored** — so the sweep survives a future
change to either `LIFETIME_SECONDS` with no backfill and no window in which old and new rows
disagree. Pruning on `issued_at` would not have that property.

**This is the shared-abstraction question answered in the only safe direction.** What is shared is
not a concept with two meanings; it is a column with one meaning. The *policy* applied to it — the
window — is still chosen independently per aggregate (decision 3). The design shares the arithmetic
and shares none of the policy.

**What a wrong guess costs, in each direction**, because a decision that lists only one side's
weaknesses is not a decision. If `expires_at` turns out not to be a real ceiling for some future
challenge — a revocable credential with no expiry at all, such as the `ApiKey` Phase 3 plans — then
the predicate simply does not apply to it, and the mismatch is a **compile error** the moment anyone
tries to reuse the method, because each method is declared on one aggregate's own repository port
with that aggregate's name on it. **The generalisation cannot silently spread**, which is the
property that matters. If we are wrong to keep the two sweeps separate, the cost is about fifteen
duplicated lines. Over-sharing produces a security-relevant predicate no unit test can see;
under-sharing produces fifteen lines.

Consequently there is **no shared sweeper port** — no `ExpiredChallengeSweeper`, no
`sweep(string $table, …)`. `countExpiredBefore()` and `deleteExpiredBefore()` are declared separately
on `EmailVerificationRequestRepository` and `PasswordResetRequestRepository`, named for their own
aggregate. A shared sweeper would have to take the window as a **parameter**, which is invariant
I-15's failure mode one level up: *if callers could pass a lifetime, then "a reset link is valid for
one hour" would not be a rule, it would be a default — and the first caller in a hurry would quietly
become the second policy.* Substitute "retention window" for "lifetime" and the sentence still holds,
on a flow that deletes data.

**2. Deletion is a set-based `DELETE` behind a port method, not load-and-delete through the
aggregate — because an aggregate governs its state transitions, not its own non-existence.**

ADR-0011 decision 4 rejected a bulk DQL `UPDATE` for a neighbouring operation, in these words:
*"a bulk update would set `invalidated_at` without the aggregate ever agreeing, putting a rule in SQL
where no unit test can reach it."* That rule is not broken here. It **generalises**, and the
distinction is precise:

- `invalidate()` is a **state transition**. It produces a new state the object must still be valid
  in, it has an invariant to protect (I-17: never both redeemed and invalidated), and a bulk `UPDATE`
  bypasses the guard protecting it. There is something to get wrong, so the aggregate must be asked.
- Deletion is **not a state transition**. It is the aggregate ceasing to exist. There is no
  post-condition, no invariant a non-existent object can violate, and no method it could refuse. A
  bulk `DELETE` bypasses **nothing**.

The general form, which is the sentence this ADR exists to put on the record:

> **Put in the Domain the part that can be wrong. A bulk operation is illegitimate when it bypasses a
> rule and legitimate when there is no rule to bypass.**

Here the part that can be wrong is the **selection** — which rows qualify — and the selection is
exactly what stays in the Domain, as a public constant and a pure static on each aggregate,
unit-testable with no kernel and no database. The mechanism is Infrastructure because it is a
statement about a storage engine, and the *workload* (batch size, per-run caps) is Application
because it is a statement about how much work one invocation should do, which is neither business
policy nor storage mechanics. A domain expert has an opinion about "we keep a recovery challenge for
a month"; they have none about 1000.

The concrete costs of the rejected load-and-delete option, so the choice is not merely philosophical:
N round trips and N hydrations for objects nobody looks at; a `ChallengeDiscarded` event that fails
the bar decision 4 sets below; and — the sharpest of the three — **one corrupt row stalls the sweep
forever, silently.** A `token_hash` that will not rehydrate throws *inside hydration*, the run dies,
the backlog grows, and the row that caused it is never named. The set-based design cannot have that
failure mode, because it never reads the hash.

**3. Retention is 7 days after expiry for verification and 30 days for reset — and the inversion
relative to the lifetimes is the point, not an oversight.**

Both are public Domain constants (`RETENTION_AFTER_EXPIRY_SECONDS`) beside the existing
`LIFETIME_SECONDS`, with a pure static `retentionThreshold(\DateTimeImmutable $now)` deriving the
instant **inside the method**, never from a parameter — I-15's rule one level up, and what makes
decision 1's rejection of a generic sweeper concrete.

The question a retention window answers is **not** "how dangerous is this row?" (not at all — it
holds a digest). It is **"what question does this row still answer after it stops working, and for
how long is anyone still asking?"** And the *durable* facts do not live in these tables at all:
`identity_user.email_verified_at` and `identity_user.password_changed_at` answer the coarse questions
forever and are never pruned. A challenge row only has to survive long enough to answer the finer
one.

- **Reset — 30 days.** The finer question is asked in an **incident review**: *when was this
  account's password reset, and from which challenge?* Takeover-notice latency is dominated by the
  victim's next login attempt, and on a marketplace a seller may not log in for weeks. Thirty days
  covers a monthly-active seller's cycle.
- **Verification — 7 days.** Nobody runs an incident review over a verification challenge; it is not
  an account-takeover primitive, which is the same distinction ADR-0011 decision 3 used to set the
  lifetimes. The only surviving question is a **support** one — *why didn't my link work?* — on a
  days-long horizon. Seven days covers one support round-trip. It is also where the volume is: issued
  automatically on every registration, capped at 5/hour rather than 3, with a 24× longer lifetime.

**So the table with the longer-lived link gets the shorter retention.** A future reader will find two
identically shaped constants with different values and will be tempted to align them, which is why a
unit test asserts the inequality with a failure message naming the reason, and why each docblock
names its twin and says why the number differs. ADR-0011's rule was *when a new file deliberately
contradicts an existing one, the contradiction is the thing that needs the comment*; this is the same
hazard wearing the opposite face — two files that **agree in shape and disagree in value**.

Domain constants rather than configuration, for the reason both `LIFETIME_SECONDS` docblocks already
give: policy expressed as an env var is policy no unit test can pin, differing between environments
for no stated reason. The accepted cost is that changing a window is a deploy.

**4. No new aggregate, no domain event, no value object, no domain exception. Each absence is a
decision.**

- **No aggregate.** Pruning is a use case over two existing aggregates' policy surface. Each gains
  exactly one constant and one static; nothing else in either diff.
- **No event.** ADR-0011 set the bar: an event must name a fact **a domain expert would recognise**
  without being taught the code, with a payload complete without a second query. *"Four thousand rows
  were deleted by a scheduled sweep"* fails both halves — it names an internal bookkeeping step, and
  nothing outside the job needs to know. The run's log line is the right home, and where an operator
  will actually look.
- **No value object.** No `RetentionWindow`, no `PruningThreshold`. A value object earns its place
  through validation, equality, or a policy that would otherwise leak into a primitive parameter. The
  window is a compile-time constant with no input to validate; the threshold is already a
  `\DateTimeImmutable`; and the policy that could leak is already prevented from leaking by the window
  not being a parameter. A wrapper would also immediately face decision 1's question again — one
  shared `RetentionWindow` is the shared policy object we just declined.
- **No exception.** Every failure available to this slice is either infrastructure (Postgres down,
  Redis down) or an operational outcome (`truncated`, `skipped: lock_held`). None is a *domain*
  failure — there is no business rule the job can violate — so a `\DomainException` subclass would be
  one nothing throws and nothing catches. The report object carries outcomes; the framework carries
  failures.

This is recorded because "add a value object" and "record an event" are reflexes this repository has
otherwise rewarded, and an unexplained absence reads as an oversight to the next person.

**5. Recurring work runs as a console command under the host's cron. `symfony/scheduler` is not
installed, and Constitution §3 is read as scoped rather than deviated from.**

The command is `muzbar:identity:prune-challenges`, with `--dry-run` and `--limit`, driven by
`17 * * * *` on the VDS host, executing inside the **`app`** container — the one the deploy pipeline
actually updates. Hourly keeps the backlog small enough that a stall is detectable in hours; the
off-the-hour minute keeps it clear of every other cron on the box.

**The §3 reading, stated explicitly because that is the point of writing it down.** The Scheduling row
reads *"Symfony Scheduler (30-day ad lifecycle)"*. The parenthetical **scopes** it to the feature that
needs in-app scheduling semantics, and Phase 2's ad lifecycle genuinely does: it must send a warning
email three days before expiry — a message with a payload, a recipient and a delivery guarantee. This
slice has no payload, no consumer waiting on a result, and gets its retry semantics free from being
idempotent and running again in an hour. **It defers to that slice rather than picking a rival
technology.** This is not a deviation and should not be read as one.

What adding Scheduler would have cost, concretely:

- A Composer dependency and a bundle in **every kernel that boots** — `app`, `messenger-worker`,
  every console command, CI and `docker build`. ADR-0010's amendment is explicit: *when you add a
  required input to the boot path, enumerate every context that boots, not every context that serves
  traffic.*
- A new Messenger transport **and a new Compose service**, because Scheduler is a transport and a
  transport needs a consumer. Folding it into `messenger-worker` is worse: one consumer draining both
  lets a slow SMTP send delay a sweep and vice versa.
- **A second daemon on a system that cannot see the first one**, and `pcntl` is absent from the
  image, so `messenger:consume` cannot shut down gracefully — every deploy kills it mid-handle. For
  mail that costs a delayed message; for a schedule it costs a missed or replayed tick with no
  observability either way.
- **And the finding that settles it, discovered while writing this slice and true today independent
  of it:** `.github/workflows/deploy.yml` runs `docker compose pull app` and
  `docker compose up -d app nginx`. **It never restarts `messenger-worker`.** The worker on the box
  runs whatever image it last had. A scheduler container added today inherits exactly that gap — and
  a scheduler silently running an **old retention policy** is a worse failure than a worker sending
  an old mail template, because it deletes data according to a rule nobody currently believes is in
  force. This is filed as a `devops` bug in its own right.

What cron costs, stated honestly: one line that lives outside the repository and is therefore
invisible to `git`. Mitigated by putting the exact line in `docs/infrastructure.md` as a runbook
entry, and by decision 7 making its absence detectable **from inside the application**.

**6. Orphan rows are collected incidentally by expiry, counted by a read-only probe, and GDPR erasure
is specified here and built nowhere.**

ADR-0009 decision 4 declined a foreign key between aggregates and accepted possible orphan rows as
the cost, saying *"the pruning job and the GDPR-erasure design own that"*. This is that job, so it
answers. **No foreign key is added. Decision 4 is answered, not reversed.**

- **Orphan-ness is not a prune criterion.** An orphan is deleted on the ordinary `expires_at`
  schedule like anything else, with no special handling and no reference to its orphan status. It is
  a state that resolves itself within the window.
- **`ChallengeIntegrityProbe` counts them and never deletes.** A DBAL `NOT EXISTS` anti-join per
  table, read-only, run after the sweep so it reports orphans currently present. A non-zero count is
  logged at **warning** and surfaced in `/health/ready`, because nothing deletes users today —
  **there are currently zero** — so any other reading means manual database surgery or a bug. A
  persistent non-zero reading across runs means something is *actively* creating them.
- **The probe implements no Domain port, and that is the decision rather than a shortcut.** *"Does
  this row's user still exist?"* is not a domain question: no use case asks it, no aggregate can
  answer it, no business rule depends on it. It is a **storage-integrity** question — precisely what
  ADR-0009 decision 4 declined to delegate to a foreign key and made "the application's job".
  Modelling it as a port would put a cross-aggregate `JOIN` into the Domain's vocabulary and licence
  a future reader to add one to a repository, which is the door this closes rather than opens. The
  counter-argument is accepted and recorded: an Infrastructure class with raw SQL and no interface is
  unswappable and has no test seam. It is exercised against the real test database like every other
  adapter, and there is nothing to swap it for.

**The erasure specification, written where the future slice will find it.** A right-to-be-forgotten
path — not built here, and not on any current branch — must:

1. **Delete the person's rows from both challenge tables *before* deleting their `identity_user`
   row.** A crash then leaves a user with no challenges, which reads as normal; the opposite ordering
   leaves orphans, which read as corruption and trip the probe's warning.
2. Treat **retention windows as inapplicable to an erasure request.** Erasure is immediate and
   complete. A design that expects "the pruner will get to it eventually" has not implemented
   erasure, and would be answering a legal obligation with an hourly cron job and a 30-day window.

**7. A background job must be able to prove it ran, and the proof must not come from the job's own
report.**

The design problem, stated plainly: **a pruning job that silently stops is indistinguishable from a
pruning job with nothing to do.** Three mechanisms, in descending order of how much they are worth:

1. **The backlog — primary.** `countExpiredBefore()` is measured *before* each sweep and reported. In
   a healthy system it is ~0 after every run; if the job stops, it grows monotonically. **It cannot
   be faked by a job that runs and does nothing** — the case where the predicate is subtly wrong and
   every other signal stays green — **and it survives Redis being flushed.** That is why it, and not
   the heartbeat, is the signal to trust.
2. **The heartbeat — secondary.** `identity:challenge_pruning:last_run`, an ISO-8601 instant from the
   `Clock`, no TTL, not written on a `--dry-run`. It distinguishes "has not run" from "ran and had
   nothing to do" *early*, before a backlog has had time to accumulate.
3. **One INFO log line per run, always**, on a dedicated `pruning` channel, including the all-zeros
   case. **The line is the record that a run happened; the deletion count is only one of its
   fields.**

`/health/ready` gains a `jobs.challenge_pruning` object in its **body** — `last_run`, `age_seconds`,
`overdue_verification`, `overdue_reset`, `stale`, with `stale` at `age_seconds > 10800` (three missed
hourly runs, so one skipped run is not an alarm). **It does not touch the status code, and that
restraint is a decision, not an omission.** Readiness answers *"should traffic come to this
instance?"*. A housekeeping job that stopped is not a reason to stop serving traffic, and a probe
that 503s over it converts a hygiene problem into an outage, with Docker restarting a perfectly
healthy container in a loop. It also keeps ADR-0010's genuinely open question — teaching
`/health/ready` about queue depth — open and separate, rather than half-answering it in a slice about
deleting rows.

**Mechanism, for completeness, since these numbers are load-bearing but not architectural:** deletion
proceeds in batches of 1000 per table, capped at 50 batches and 30 seconds of wall clock per run;
hitting either reports `truncated: true` and **exits 0**, because the next run continues and nothing
is lost or repeated. **One `Clock::now()` pins both thresholds for the run**; the wall-clock cap calls
`now()` again on purpose, and the two uses are distinguished at the call site, because conflating them
would let the threshold drift mid-run behind a `FrozenClock` that hides it. A Redis `SET NX` run lock
prevents overlap — and **the lock is politeness, not correctness.** Correctness comes from
idempotency. With Redis unavailable the run **proceeds** and logs at warning, because housekeeping
must not stop because a cache is down; the code says so at the acquisition site, so nobody later
"hardens" it into a hard requirement.

## Alternatives

- **A `Challenge` base class, interface or shared sweeper port** spanning the two aggregates.
  **Rejected** per decision 1 — this is ADR-0011 decision 9's rejection re-derived one level down,
  where it would live in SQL and no unit test could reach it. The shared thing is a column with one
  meaning, not a concept with two.
- **Load each row and delete through the aggregate.** The DDD-honest-*looking* option. **Rejected**
  per decision 2, on the rule that an aggregate has no opinion about its own non-existence, and on
  the corrupt-row failure mode, which is the practical argument that decides it.
- **Prune on `issued_at` + lifetime + retention** rather than on the stored `expires_at`.
  **Rejected**: it recomputes a value the row already carries, and a future change to either
  `LIFETIME_SECONDS` would silently re-judge every historical row, requiring a backfill nobody would
  remember to write.
- **A fuller predicate using each table's terminal columns**, deleting used and cancelled rows sooner.
  **Rejected** per decision 1, and note what it would actually buy: the rows it would remove early are
  precisely the ones whose retention window exists to answer a support or incident question. It
  optimises away the feature.
- **`symfony/scheduler` now**, per a literal reading of Constitution §3. **Rejected** per decision 5,
  primarily on the second-invisible-daemon argument and the deploy pipeline finding. Revisit when the
  ad lifecycle needs it — at which point this command can be triggered by it with no change to
  anything below the console layer, because the command is needed under either option and only the
  trigger differs.
- **`pg_try_advisory_lock` instead of a Redis lock.** Same connection, no new dependency, released
  automatically on disconnect, and immune to the fail-open path. Genuinely defensible, and **flagged
  as the most defensible place in this ADR to disagree.** Declined because it puts a
  Postgres-specific concurrency primitive in the adapter for a property that is explicitly not a
  correctness requirement. `symfony/lock` was also declined: a new package for a property idempotency
  already provides.
- **Adding the foreign key after all**, now that we are finally cleaning up. **Rejected**: ADR-0009
  decision 4's reasoning is unchanged — an FK the mapping does not know about is diffed as unwanted on
  every `make migration.make`, deleted and re-added forever — and the orphan cost it was accepted
  against is now measured (decision 6) rather than merely tolerated.
- **`/health/ready` returning 503 on a stalled pruner.** **Rejected** per decision 7. It is the
  version of this design that looks more rigorous and is strictly worse.
- **Archiving rows to cold storage before deletion.** A second store, a second retention policy and a
  second thing to forget about, for rows holding a digest and four timestamps. **Rejected** unless a
  compliance requirement ever names it.
- **Pruning `messenger_messages` and the failure transport in the same job.** They grow too, and they
  are Messenger's tables with Messenger's semantics. A job named for `Identity` challenges must not
  quietly acquire them. **Rejected**, and filed as a separate `devops` item so that naming it is not
  the same as forgetting it.

## Consequences

- **Easy:** the retention policy is pure PHP, unit-testable with no kernel and no database, and the
  safety property that matters falls out of arithmetic rather than a guard — a live challenge has
  `expires_at ≥ now` and the threshold is strictly less than `now`, so **a live challenge cannot be
  selected by any run at any limit** (I-26). Nothing new is installed: no Composer package, no env
  var, no Compose service, no Messenger transport, no port alias. The sweep is immune to a corrupt
  row and to a future change of either lifetime. One migration, purely additive: two indexes on
  `expires_at`, justified by the **batching** — without them a fifty-batch run is fifty sequential
  scans — rather than by table size.
- **Hard / watched:**
  - **This is the first `DELETE` this repository has ever shipped**, and "we have never deleted
    before" is exactly the condition under which a bad delete goes unnoticed. Both
    `deleteExpiredBefore()` implementations are read by hand at verification rather than taken on
    trust from a green suite. The `:threshold` parameter is bound with `Types::DATETIMETZ_IMMUTABLE`
    explicitly on every call — the naive inference is the single most likely bug in the slice, and it
    is **invisible on a UTC box**.
  - **The retention windows are judgement calls with no backstop.** If a window turns out too short,
    the only recovery is a database dump — and `make db.dump` is on-demand and unscheduled, with a
    daily dump still listed as a to-do in `docs/infrastructure.md`. **The dump schedule and these
    windows should be decided together**; today there is nothing behind them.
  - **Nothing alerts.** Decision 7 makes the failure *visible*; noticing it still requires someone to
    look. Fourth consecutive slice to write that sentence, and the first shipping work whose only
    symptom of failure is the absence of a symptom. **Sentry remains the highest-value `devops`
    item**, and this ADR is the strongest argument yet made for it.
  - **The cron line lives outside git**, so a rebuilt box silently has no pruning until someone
    reinstalls it. That failure is precisely what the backlog and the `stale` flag exist to make
    visible within three hours.
  - **`deploy.yml` never restarts `messenger-worker`** (decision 5). Independent of this slice, true
    today, and now written down.
  - **`/health/ready` grows two `COUNT` queries per probe.** Bounded by the new indexes and by the
    sweep keeping the tables small, and it is a Docker healthcheck rather than user traffic. If it
    ever hurts, the fallbacks are a short-TTL cache of the counts or a separate `/health/jobs` — both
    strictly more machinery, which is why neither is here.
  - **The orphan probe joins two aggregates' tables in SQL.** Deliberately Infrastructure-only,
    port-less and incapable of deleting, but a reader could still take it as precedent for putting
    one in a repository. Its docblock closes that door explicitly, and it must not acquire a delete
    method later.
  - **Two more pieces of Redis state that DAMA does not roll back** — the run lock and the heartbeat
    — joining the rate limiters. The cheap proof of getting it right is `make test` **twice in a
    row**.
  - **Erasure is specified and unbuilt.** The ordering rule and the retention carve-out are recorded
    here and in `docs/infrastructure.md`, and neither is enforced by anything. The first slice to
    delete a user must read both.
