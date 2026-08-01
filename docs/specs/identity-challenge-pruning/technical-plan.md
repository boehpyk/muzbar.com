# Technical Plan: identity-challenge-pruning

> The *how*. Disposable. Written after the feature-spec is drafted, approved with it before any code.
> Follows DDD canonical order.

**Bounded context:** `Identity` (Constitution §4). Nothing outside `Identity` and `Shared` is
touched, and inside `Identity` nothing new is *modelled* — this slice adds behaviour to two existing
aggregates' **policy surface** and one new use case over them.

**Namespaces claimed by this slice**

```
src/src/Domain/Identity/          Entity/EmailVerificationRequest  (+1 const, +1 static)
                                  Entity/PasswordResetRequest      (+1 const, +1 static)
                                  Port/EmailVerificationRequestRepository (+2 methods)
                                  Port/PasswordResetRequestRepository     (+2 methods)
src/src/Application/Identity/     Command/PruneExpiredChallenges,
                                  Handler/PruneExpiredChallengesHandler,
                                  ChallengePruningReport, SweepOutcome
src/src/Infrastructure/Identity/  Persistence/Doctrine/DoctrineEmailVerificationRequestRepository (+2)
                                  Persistence/Doctrine/DoctrinePasswordResetRequestRepository     (+2)
                                  Persistence/Doctrine/ChallengeIntegrityProbe   (new, no port)
                                  Console/PruneChallengesCommand                 (new)
src/src/Infrastructure/Http/      Controller/HealthController  (+1 reporting-only body section)
```

**Unchanged on purpose:** both aggregates' behaviour (every method, both `LIFETIME_SECONDS`, both
`MAX_ISSUES_PER_HOUR`, all four inversions, both save orderings), every value object, every event,
every existing port method, both XML mappings (no new field, no new index element — see *Data &
migrations* for why the index is migration-only), `security.yaml`, `rate_limiter.yaml`,
`messenger.yaml`, both compose files, `composer.json`.

---

## Decisions needing sign-off

Eleven, ordered by how much they change the shape of the slice. Recommendations are made in every
case — none of these is a "you decide".

> **All eleven signed off 2026-08-01, each adopted as recommended.** The four gating T0 were decided
> explicitly rather than by omission. On **decision 6** the owner chose the *scoped* reading of
> Constitution §3 — its `(30-day ad lifecycle)` parenthetical scopes Symfony Scheduler to the feature
> that needs in-app scheduling semantics — over recording a deviation. **ADR-0012 must therefore
> state that reading in those words**, so a future reader reconciling §3 with a cron line finds the
> argument rather than a contradiction.

| # | Decision | Recommendation |
|---|---|---|
| 1 | **The prune predicate is `expires_at + retention`, deliberately *not* either table's notion of "dead"** | **Adopt.** The two tables disagree about "dead" by design; `expires_at` is the one thing they agree about, and it is a *ceiling* on every other reason a row is finished. Argued in full below. → **ADR-0012** |
| 2 | **Two per-aggregate port methods, no shared sweeper abstraction** | **Adopt.** The retention *window* differs per aggregate, so a generic sweeper would have to take it as a parameter — which is invariant I-15's failure mode one level up. |
| 3 | **Set-based `DELETE` behind a port method, not load-and-delete through the aggregate** | **Adopt**, with the rule that generalises it written down: *an aggregate governs state transitions, not its own non-existence; put in the Domain the part that can be wrong.* → **ADR-0012** |
| 4 | **No new aggregate, no domain event, no value object, no exception** | **Confirm.** Each absence is argued below rather than left as an omission. |
| 5 | **Retention windows: 7 days (verification) / 30 days (reset)** — and the inversion relative to the lifetimes is the point | **Adopt.** Argued from the question each row answers *after* it dies, not from tidiness. |
| 6 | **A console command under host cron; `symfony/scheduler` deferred to the slice that needs in-app scheduling** | **Adopt.** Touches **Constitution §3**, so it must be recorded rather than assumed. → **ADR-0012** |
| 7 | **Observability: one log line always + Redis heartbeat + a reporting-only `/health/ready` body section that cannot change the status code** | **Adopt.** The **backlog**, not the heartbeat, is the primary signal — see the argument. |
| 8 | **Batch 1000, 50 batches/table/run, 30 s wall clock, Redis `SET NX` run lock that is politeness rather than correctness** | **Confirm.** |
| 9 | **Two new indexes on `expires_at`, one migration, nothing else** | **Adopt.** The justification is the *batching*, not the table size. |
| 10 | **Orphans: collected incidentally by expiry, counted by a read-only Infrastructure probe, and erasure specified but not built** | **Adopt.** Discharges ADR-0009 decision 4's clause without reversing it. → **ADR-0012** + a dated amendment to **ADR-0009** |
| 11 | **`--dry-run`, `--limit`, and a rehearsed first run** | **Confirm.** |

---

## ⚠ Decision 1 — the predicate, argued honestly

### The trap this slice was set up to walk into

CLAUDE.md and ADR-0011 decision 9 both say, at length, that `EmailVerificationRequest` and
`PasswordResetRequest` must not share a base class, because they share a *shape* and not
*behaviour*, and because a shared guess on any of their four inverted rules is right for one and a
latent security bug in the other.

A pruning slice arrives with an obvious-looking shared abstraction ready-made: *"delete the dead
rows from both tables."* That sentence is the base class again, expressed as a `WHERE` clause. Worse,
it would live in SQL, where — as ADR-0011 decision 4 already argued about the bulk `UPDATE` it
declined — **no unit test can reach it.**

So the first job is to check whether "dead" is even one concept. It is not. Verified against the
shipped mappings and migrations rather than against memory:

| | `identity_email_verification_request` | `identity_password_reset_request` |
|---|---|---|
| Terminal columns | `redeemed_at` | `redeemed_at`, **`invalidated_at`** |
| Replay of a redeemed row | **absorbed** (friendly no-op) | **refused** |
| Reissue | leaves siblings alive | **invalidates all siblings** |
| A cross-aggregate reason to be finished | an outstanding row is **inert** once `identity_user.email_verified_at` is set | a row is **stale** once `issued_at < identity_user.password_changed_at` |
| Rows per user per hour | ≤ 5 | ≤ 3 |
| Lifetime | 86 400 s | 3 600 s |

Note the fourth row especially. **Both tables have a notion of "finished" that is not visible in the
row at all** — it requires the user's row — and the two notions are *different*
(`email_verified_at` vs `password_changed_at`). A "dead" predicate that wanted to be complete would
have to join `identity_user` twice, with two different comparisons, and would then be encoding two
aggregates' cross-boundary rules in one SQL statement in the Infrastructure layer.

### The answer: do not ask what "dead" means

Every one of those reasons is bounded above by the same thing. A redeemed row expires. An invalidated
row expires. A stale row expires. An inert row expires. **`expires_at` is a ceiling on all of them**,
and it is:

- the one column both tables define identically (`TIMESTAMP(0) WITH TIME ZONE NOT NULL`);
- derived identically and un-overridably by both aggregates (`issuedAt + LIFETIME_SECONDS`,
  invariants I-8 and I-15, computed inside `issue()` and never a parameter);
- **already stored**, so it survives a future change to either `LIFETIME_SECONDS` without a backfill
  (AC-9) — which pruning on `issued_at` would not;
- a fact about *the row*, requiring no join, no clock inside SQL and no cross-aggregate reasoning.

So the sweep's entire predicate is:

```
expires_at < (now − RETENTION_AFTER_EXPIRY_SECONDS)
```

and the two tables' disagreement is **never consulted**. AC-7 pins that: `invalidated_at`,
`redeemed_at`, `password_changed_at` and `email_verified_at` may not appear in any pruning predicate,
in any layer.

**This is the shared abstraction question answered in the only safe direction.** The thing being
shared is not a concept with two meanings; it is a column with one meaning, and the retention
*window* applied to it is still chosen independently per aggregate (decision 5). The design shares
the arithmetic and shares none of the policy.

### What a wrong guess costs, in each direction

Stated because a decision that only lists one side's weaknesses is not a decision.

**If we are wrong to generalise** (i.e. `expires_at` turns out not to be a real ceiling for some
future challenge type): the failure mode is a row that lives longer than it should. Concretely, a
future revocable credential with **no** expiry — an `ApiKey`, which Phase 3 already plans — has no
`expires_at` at all, so this predicate simply does not apply to it and the mismatch is a *compile
error* the moment someone tries to reuse the method, because the port method is declared on a
specific repository with a specific aggregate's name on it (decision 2). **The generalisation cannot
silently spread**, which is the property that matters.

**If we are wrong to keep them separate** (i.e. the two sweeps really were one thing): the cost is
about fifteen duplicated lines across two adapters and two port methods, plus two entries in one
handler. Cheap, local, non-viral — exactly the three criteria ADR-0011 used to accept the aggregate
duplication.

The asymmetry is decisive: over-sharing here produces a security-relevant predicate that no unit test
can see; under-sharing produces fifteen lines.

### The asymmetry that drives everything in this context — restated accurately

The framing usually offered is: *deleting a reset row too early is a denial of recovery; too late
leaves a live account-takeover credential in the database.* The first half is right; **the second
half is not, and getting it right changes which window you pick.**

A stored row holds the **SHA-256 digest** of the token (ADR-0011 decision 2). The plaintext exists
only in the user's inbox and, briefly, in a session. So a row that outlives its usefulness is not a
live credential — it is the digest of a dead secret, which is unusable at any cost. The real costs of
keeping it are different and worth naming precisely:

- **Deleting too early, on a *live* row** — catastrophic and silent: the user's link stops working
  and nothing says why. **Structurally impossible here** (AC-5, I-26): a live row has
  `expires_at ≥ now`, and the threshold is strictly less than `now`.
- **Deleting too early, on a *dead but recent* row** — real and modest: a replay gets
  `PasswordResetRequestNotFound` instead of `PasswordResetLinkAlreadyUsed`. The visitor sees the same
  neutral response either way (slice 3's AC-16), so the loss is entirely in the log and in an
  incident review. This is exactly what the retention window buys (AC-6).
- **Deleting too late** — a **data-protection** cost, not a credential cost. `(user_id, issued_at)`
  is a dated record that this person asked to recover their account. It is personal data under GDPR
  (Constitution §8), it serves no purpose once the challenge is dead and the window has passed, and
  the fact that it is *only* metadata is not a defence for keeping it indefinitely.

That reframing is why the windows are measured in **days**, and why "keep it forever, it's tiny" is
rejected even though the rows genuinely are tiny.

---

## Reuse vs duplication — decision 2

Three shapes were considered.

**(A) Two methods on each existing repository port** — `countExpiredBefore()` and
`deleteExpiredBefore()`, declared separately on `EmailVerificationRequestRepository` and
`PasswordResetRequestRepository`, each with its own docblock. **Recommended.**

**(B) A new shared `Domain/Identity/Port/ExpiredChallengeSweeper`** with something like
`sweep(string $collection, \DateTimeImmutable $threshold, int $limit): int`. **Rejected outright**:
a port whose first parameter selects a table is SQL wearing a Domain hat. It would also make the
sweeper the *only* place in `Identity` where an aggregate is addressed by string rather than by type.

**(C) One `ChallengeSweeper` port with the window as a parameter** — the tempting middle. **Rejected,
and the reason is an existing rule rather than taste.** The retention window is per-aggregate policy
(decision 5), so a shared sweeper must accept it from the caller. That is precisely invariant I-15's
failure mode one level up: *"if callers could pass a lifetime, then 'a reset link is valid for one
hour' would not be a rule, it would be a default — and the first caller in a hurry would quietly
become the second policy."* Substitute "retention window" for "lifetime" and the sentence still
holds, on a flow that deletes data.

There is a second reason, cheaper but real: the two ports already carry docblocks promising *"the
pruning job owed later will add its own method when it is written, with its own justification."*
Adding a third port instead would leave both promises unkept and both docblocks lying.

**What *is* shared, honestly:** the arithmetic (`now − constant`), the strict `<`, the batching loop,
the console command, the report type, the log line, the lock and the heartbeat. All of that is one
implementation, in the Application and Infrastructure layers, where reuse belongs — the same split
ADR-0011 named: *"the reuse is at the level of ports and infrastructure; the duplication is at the
level of policy objects."*

---

## Domain layer (pure PHP)

Zero `use Symfony\...` / `use Doctrine\...`. Only core PHP (`\DateTimeImmutable`, `\DateInterval`).

### The layer split — decision 3, and the lesson this slice exists to teach

Pruning is simultaneously two things, and the whole design turns on separating them:

| | Lives in | Why |
|---|---|---|
| **The policy** — how long after expiry a row is worth keeping, and therefore *which rows* qualify | **Domain** | It is a business statement a domain expert would make ("we keep a recovery challenge for a month"), it is the part that **can be wrong**, and it is unit-testable with no kernel and no database. |
| **The mechanism** — a batched, set-based `DELETE` | **Infrastructure** | It is a statement about a storage engine. It cannot be wrong in a way the Domain could catch, and it cannot be expressed at all without Doctrine. |
| **The workload** — batch size, per-run caps | **Application** | Neither business policy nor storage mechanics: a statement about *how much work one invocation should do*, which is a use-case concern. A domain expert has no opinion about 1000. |

**Why not load-and-delete through the aggregate.** This is the DDD-honest-looking option and it must
be argued down rather than waved away, because ADR-0011 decision 4 explicitly rejected a bulk `UPDATE`
for a neighbouring operation:

> *"through the aggregate rather than a bulk DQL `UPDATE` — a bulk update would set `invalidated_at`
> without the aggregate ever agreeing, putting a rule in SQL where no unit test can reach it."*

Does that rule apply here? **No, and the distinction is precise:**

- `invalidate()` is a **state transition**. It produces a new state the object must still be valid
  in, it has an invariant to protect (I-17: never both redeemed and invalidated), and a bulk `UPDATE`
  bypasses the guard that protects it. There is something to get wrong, so the aggregate must be
  asked.
- Deletion is **not a state transition**. It is the aggregate ceasing to exist. There is no
  post-condition, no invariant a non-existent object can violate, and no method it could refuse. A
  bulk `DELETE` bypasses **nothing**, because the aggregate has no opinion about its own
  non-existence.

The thing that *can* be wrong is the **selection**, and the selection is exactly what stays in the
Domain. So ADR-0011's rule is not broken here; it **generalises**, and the general form is the
sentence to put in ADR-0012:

> **Put in the Domain the part that can be wrong. A bulk operation is illegitimate when it bypasses a
> rule and legitimate when there is no rule to bypass.**

The concrete costs of the rejected option, so the choice is not merely philosophical:

- **N round trips and N hydrations** for objects nobody looks at — six DBAL conversions each, on
  rows whose entire purpose is to disappear. N is unbounded by default.
- **One corrupt row stalls the sweep forever, silently.** A `token_hash` that will not rehydrate
  throws inside hydration, the run dies, the backlog grows, and the row that caused it is never
  reported. AC-17 asserts the set-based design does not have this failure mode; it is the sharpest
  practical argument of the three.
- It would want a `ChallengeDiscarded` **domain event**, which fails the same two-part test ADR-0011
  used to decline `PasswordResetRequestInvalidated`: an event must name a fact a domain expert would
  recognise, and *"a row we wrote a month ago was deleted by housekeeping"* names a bookkeeping step
  the system performs on itself.

### Aggregate changes — decision 4 (`EmailVerificationRequest`, `PasswordResetRequest`)

**Exactly one constant and one static method on each. Nothing else** (AC-30).

```php
// EmailVerificationRequest
public const int RETENTION_AFTER_EXPIRY_SECONDS = 604800;   //  7 days

// PasswordResetRequest
public const int RETENTION_AFTER_EXPIRY_SECONDS = 2592000;  // 30 days

// both, identically shaped, deliberately not shared:
public static function retentionThreshold(\DateTimeImmutable $now): \DateTimeImmutable
{
    return $now->sub(new \DateInterval(\sprintf('PT%dS', self::RETENTION_AFTER_EXPIRY_SECONDS)));
}
```

Notes the implementer must honour:

- `sub()` with an explicit `\DateInterval`, mirroring `issue()`'s `add()`, for the same reason:
  it leaves timezone and sub-second field untouched, so the threshold inherits the `Clock`'s UTC and
  whole-second guarantees rather than reinterpreting them. **`strtotime`/`modify('-7 days')` is
  banned here** for `issue()`'s stated reason — it is a natural-language grammar, and dates are not
  a place for one.
- A **static** rather than an instance method because it is a statement about the *type's* policy,
  not about any one request — the same category as `LIFETIME_SECONDS`, and the same category as
  `issue()`, which is already static.
- The window is **derived inside the method** and is not a parameter. That is I-15's rule one level
  up, and it is what makes decision 2's rejection of a generic sweeper concrete: a caller cannot
  supply a window, so no caller can become the second policy.
- The docblock on each must say **why its number differs from its twin's**, and must name the twin.
  Slice 3's rule: *when a new file deliberately contradicts an existing one, the contradiction is the
  thing that needs the comment.* Here the two files agree in shape and disagree in value, which is
  the same hazard.

### Why the retention numbers are what they are — decision 5

The question a retention window answers is **not** "how dangerous is this row?" (the answer is: not
at all — it holds a digest). It is **"what question does this row still answer after it stops
working, and for how long is anyone still asking?"**

**The durable facts do not live in these tables.** `identity_user.email_verified_at` answers *"is
this address verified, and since when?"* forever. `identity_user.password_changed_at` answers *"was
this account's password changed, and when?"* forever. Neither is pruned. So a challenge row only has
to survive long enough to answer the **finer** question — *which* challenge, issued when, redeemed or
superseded — and that is a much shorter horizon than "forever".

**`PasswordResetRequest` — 30 days.** The finer question here is asked during an **incident review**:
*"when was this account's password reset, and from which challenge?"* Account-takeover notice latency
is dominated by the victim's next login attempt, and on a marketplace a seller may not log in for
weeks. Thirty days covers a monthly-active seller's full cycle. Beyond that the row answers a
question nobody is still asking, while `password_changed_at` keeps answering the coarse one.

**`EmailVerificationRequest` — 7 days.** Nobody runs an incident review over a verification
challenge; it is not an account-takeover primitive, which is the same distinction ADR-0011 decision 3
used to set the lifetimes. The only question the row still answers is a **support** one — *"why
didn't my link work?"* — with a days-long horizon, and `email_verified_at` already answers the coarse
version. Seven days covers one support round-trip and nothing more. It is also where the **volume**
is: a verification request is issued automatically on every registration, the cap is 5/hour rather
than 3, and the lifetime is 24× longer, so this table accumulates faster in every dimension.

**The inversion is the point, and AC-3 pins it.** The table with the *longer-lived link* gets the
*shorter retention*, because retention is about the question the row answers after it dies rather
than about how long it lived. A future reader will notice the two numbers disagree and will be
tempted to align them; the assertion in AC-3 carries the reason in its failure message so that
alignment fails loudly.

**Both are Domain constants, not configuration**, for the reason both `LIFETIME_SECONDS` docblocks
already give: policy expressed as an env var is policy no unit test can pin and that differs between
environments for no stated reason. The accepted cost is that changing a window is a deploy.

**What has *no* backstop today, and should be signed off knowingly:** if a window turns out to be too
short, the only recovery is a database dump — and `make db.dump` is on-demand and unscheduled
(`docs/infrastructure.md` still lists a daily dump as a to-do). Recommend deciding the dump schedule
and these windows together. Recorded as Risk 4.

### Value objects — decision 4, the negative case

**None. No `RetentionWindow`, no `PruningThreshold`, no `BatchSize`.**

Argued rather than omitted, because "add a value object" is the reflex this repository has otherwise
rewarded. A value object earns its place when it carries *validation*, *equality* or *a policy that
would otherwise leak into a primitive parameter*. Here:

- The window is a compile-time constant with no user input to validate and no invalid value to
  refuse.
- The threshold is a `\DateTimeImmutable` — already a value type, already compared by value, and
  wrapping it would only add an unwrapping call at every use.
- The policy that could leak *is already prevented from leaking* by the window not being a parameter.

And a wrapper would immediately face the shared-abstraction question again: one `RetentionWindow`
used by both aggregates is the shared policy object decision 2 declines, and two near-identical
wrappers is ceremony with no payer.

### Domain events — decision 4, the negative case

**None.** `PasswordResetRequest::invalidate()`'s docblock already states the bar an event must clear:
it must name a fact **a domain expert would recognise** without being taught the code, and its
payload must be complete without a second query. *"Four thousand rows were deleted by a scheduled
sweep"* fails both halves — it names an internal bookkeeping step, not a business occurrence, and
nothing outside the job needs to know it. The run's log line (AC-20) is the right home for that
information, and it is where an operator will actually look.

### Domain exceptions — decision 4, the negative case

**None.** Every failure this slice can have is either an infrastructure failure (Postgres down,
Redis down) or an operational outcome (`truncated`, `skipped: lock_held`). None of them is a *domain*
failure — there is no business rule the job can violate — so a `\DomainException` subclass would be
an exception no domain code throws and no domain code catches. The report object carries the
outcomes; the framework carries the failures.

### Invariants *(continuing I-1…I-24)*

| # | Invariant | Protected by |
|---|---|---|
| I-25 | `retentionThreshold($now)` is always exactly `$now − RETENTION_AFTER_EXPIRY_SECONDS`, per aggregate. | Derived inside the static; the window is not a parameter, so no caller can widen or narrow it. I-15's rule, one level up. |
| I-26 | **A live challenge is never deleted.** | Not an aggregate invariant — it spans the aggregate and the sweep. It follows *structurally* rather than by a guard: a live row has `expires_at ≥ now`, the threshold is `now − w` with `w > 0`, and the predicate is `expires_at < threshold`; therefore no live row qualifies. Asserted at both sides of the boundary (AC-4) **and** directly (AC-5), because a property that holds by arithmetic still has to be pinned by a test that would fail if the arithmetic changed. |
| I-27 | Pruning never mutates `identity_user`. | Not an invariant of anything — a *structural* fact held by the absence of any write path, asserted with the SQL logger (AC-8). Stated because the two cross-aggregate "finished" notions (§*Decision 1*) are exactly the road by which a future change would acquire one. |

### Ports (interfaces) — the four new methods

Declared **separately** on the two existing ports, with the aggregate's own name in the docblock.

`Domain/Identity/Port/EmailVerificationRequestRepository`

```
countExpiredBefore(\DateTimeImmutable $threshold): int
deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
```

`Domain/Identity/Port/PasswordResetRequestRepository` — the same two signatures, declared
independently.

Contract points every docblock must carry:

- **`$threshold` is passed in, never computed here.** Same rule as `countIssuedForUserSince()`: an
  instant comes from the `Clock` port, and a repository that knew what time it was could answer
  questions whose truth changes between the query and the assertion.
- **The comparison is strict `<`** and that is part of the contract, not an implementation detail
  (AC-4). At whole-second storage a `>=`/`<=` slip moves the boundary by a second in a way only a
  two-sided test can catch.
- **The predicate is `expires_at` and nothing else** — no `redeemed_at`, no `invalidated_at`, no
  join. The docblock must say *why*, pointing at §*Decision 1*, because "surely we should also delete
  redeemed rows early" is the exact improvement a future reader will offer.
- **`deleteExpiredBefore()` returns the number of rows actually deleted**, so the handler's loop
  terminates on `< $limit` and the report's counters are measured rather than assumed. A test asserts
  the return value equals the observed row delta, never a literal.
- **`$limit` is mandatory, not optional with a default.** An optional limit is an unbounded delete
  waiting for the one caller who omits it.
- **Neither method hydrates an aggregate**, and the docblock says so — that is what AC-17 turns into
  a test, and what makes a corrupt row a deleted row rather than a stalled sweep.
- **Deliberately absent:** any `deleteRedeemed()`, `deleteForUser()`, or a `findExpiredBefore()`
  returning objects. `deleteForUser()` in particular is the erasure feature's method, and it must be
  added by the slice that has a caller and a decision behind it (decision 10).

---

## Application layer

Thin, framework-free, depends only on `Domain`.

### Command and result

| Class | Fields | Notes |
|---|---|---|
| `Command/PruneExpiredChallenges` | `?int $limit`, `bool $dryRun` | Primitives only, slice 1's rule. `$limit` `null` means "the configured per-run cap". |
| `ChallengePruningReport` | `SweepOutcome $verification`, `SweepOutcome $reset`, `int $orphanedVerification`, `int $orphanedReset`, `bool $dryRun` | `final readonly`. Lives directly under `Application/Identity/`, following `VerificationOutcome`'s precedent. |
| `SweepOutcome` | `\DateTimeImmutable $thresholdAt`, `int $overdueBefore`, `int $deleted`, `int $batches`, `bool $truncated` | `final readonly`. |

**Why the handler returns a report at all,** when slice 3's commands return `void`: the caller needs
every number for the log line and for the console output, and re-querying to build it would produce a
report of a *different* moment than the one that was swept. Slice 2's `VerificationOutcome`
established that an Application-layer result type is legitimate when the adapter genuinely cannot
reconstruct the answer; this is the same situation with more fields.

**Why `dryRun` is on the command and not a second handler:** "tell me what this would do" is a
legitimate variant of the same use case with the same policy, the same thresholds and the same
counts. Two handlers would duplicate the threshold derivation, which is the part that must not drift.

### `PruneExpiredChallengesHandler::__invoke(PruneExpiredChallenges $command): ChallengePruningReport`

```
BATCH_SIZE          = 1000
MAX_BATCHES_PER_TABLE = 50      // ⇒ 50 000 rows per table per run
MAX_RUN_SECONDS       = 30
```

These three are **Application** constants (AC-34). They describe how much work one invocation should
do, which is neither a business statement nor a storage mechanic. A domain expert has no opinion
about 1000; an operator does.

Flow:

1. `$now = $this->clock->now();` — **one `now()` for the whole run**, slice 3's rule for slice 3's
   reason: two thresholds derived from two instants would be two statements pretending to be one, and
   a frozen clock in a test makes the discrepancy invisible.
2. `$verificationThreshold = EmailVerificationRequest::retentionThreshold($now);`
   `$resetThreshold = PasswordResetRequest::retentionThreshold($now);`
3. Per table, **before** sweeping: `countExpiredBefore($threshold)` → `overdueBefore`. This is the
   backlog number (AC-24) and it is measured *before* any deletion, because a number measured after
   is always ~0 and therefore says nothing about whether the job has been running.
4. If `$command->dryRun` — stop here for this table: `deleted = 0`, `batches = 0`,
   `truncated = overdueBefore > 0`.
5. Otherwise loop: `$deleted = deleteExpiredBefore($threshold, BATCH_SIZE)`, accumulate, until
   `$deleted < BATCH_SIZE` **or** `batches === MAX_BATCHES_PER_TABLE` **or**
   `$this->clock->now() >= $runDeadline`. The last two set `truncated = true`.
6. Repeat 3–5 for the second table, then assemble the report.

**The two uses of `Clock` are different and must carry a comment.** Step 1's instant is *pinned*: it
defines what "overdue" means for this run, and it must not move, or rows that became overdue during
the run would be swept non-deterministically. Step 5's calls are a *stopwatch*. Conflating them is
the subtle bug here, and a `FrozenClock` would hide it (the deadline would never arrive) — which is
why `MAX_BATCHES_PER_TABLE` exists as a second, clock-independent cap that a test *can* exercise.

**Batching lives here rather than in the adapter** because "how much work per run" is the use case's
decision, and because it keeps the adapter method a single statement — easier to reason about, easier
to `EXPLAIN`, and swappable without re-implementing the loop.

**Why `< BATCH_SIZE` rather than `=== 0` terminates the loop:** a short batch means the table is
drained, so the extra round trip a `=== 0` condition would always cost at the end is avoided. Worth a
comment because it looks like an off-by-one.

### Idempotency

- **Idempotent by construction.** A `DELETE` matching a predicate is naturally idempotent: a second
  run deletes nothing (AC-13). There is no state to reconcile, no marker to check and nothing to
  compensate.
- **A run interrupted mid-sweep** leaves whole batches committed. There is no partial row state to
  have, because a row is either deleted or not (AC-15). The next run continues from wherever it got
  to, with no knowledge that a previous run existed.
- **That is why the run lock is politeness rather than correctness** (decision 8). Two overlapping
  runs would produce correct results and waste transactions; skipping is simply the better use of the
  box. This must be written at the acquisition site so nobody later "hardens" the lock into a
  correctness dependency and then makes Redis a hard requirement of housekeeping.

### Transaction boundary

Each `deleteExpiredBefore()` call is its own transaction (the adapter executes and commits one
statement). **That is the design, not a leak:** one long transaction covering the whole run would
hold locks and grow WAL for as long as the run takes, and would make the resumability in AC-14
impossible. Nothing spans the two tables — there is no invariant between them, which is the whole
reason two aggregates were legitimate in the first place.

---

## Infrastructure layer

### Persistence — the two adapters

Each Doctrine adapter gains the two methods. Set-based DQL, no hydration, one statement per call.

```sql
-- countExpiredBefore
SELECT COUNT(r.id) FROM <Aggregate> r WHERE r.expiresAt < :threshold

-- deleteExpiredBefore  (executed as native SQL — see below)
DELETE FROM <table>
 WHERE id IN (
     SELECT id FROM <table>
      WHERE expires_at < :threshold
      ORDER BY expires_at
      LIMIT :limit
 )
```

Implementation notes the implementer must not improvise:

- **`:threshold` is bound with `Types::DATETIMETZ_IMMUTABLE` explicitly**, exactly as
  `countIssuedForUserSince()` already does and for the same discovered reason: Doctrine's
  `ParameterTypeInferer` would pick the **naive** `datetime_immutable`, formatting without a UTC
  offset against a `TIMESTAMP WITH TIME ZONE` column — a silent window shift that is invisible on a
  UTC box and wrong on any other. This is the single most likely bug in the slice.
- **The `DELETE` is native SQL via `Connection::executeStatement()`, not DQL.** DQL's `DELETE` has no
  `LIMIT` and no subquery in its `WHERE IN`, so the batching AC-11 requires is not expressible in it.
  This is Infrastructure choosing the right tool for a mechanism, and the table and column names are
  spelled out literally — consistent with ADR-0007 decision 6, which already refuses to let anything
  derive a name.
- **The `ORDER BY expires_at` inside the subquery is load-bearing**, not decoration: it makes each
  batch the *oldest* remaining rows, so a truncated run makes monotone progress and the index range
  scan walks the left edge of the tree. Without it, batching would sample arbitrarily and a capped
  run's progress would be unpredictable.
- **Both methods return `int`**; `COUNT` arrives from the PostgreSQL driver as a `bigint`, which PDO
  surfaces as a *string*, so the cast is what makes the return type honest — the same note the
  existing `countIssuedForUserSince()` carries.
- **No `SELECT … FOR UPDATE SKIP LOCKED`.** It is the textbook answer to two workers picking the same
  ids, and it is unnecessary here: the run lock makes overlap rare and idempotency makes it harmless.
  Adding it would buy a property already held and would put a concurrency primitive into a job that
  runs once an hour. Named here so that a reviewer who reaches for it finds the reason.
- **`save()` and `findByTokenHash()` are untouched.** The new methods sit below them with a comment
  saying they were added by `identity-challenge-pruning`, discharging the "no `deleteExpired()` here
  yet" note both ports carry.

### The orphan probe — decision 10

`Infrastructure/Identity/Persistence/Doctrine/ChallengeIntegrityProbe`, constructed with
`Doctrine\DBAL\Connection`. Two methods, both read-only:

```sql
SELECT COUNT(*) FROM identity_email_verification_request r
 WHERE NOT EXISTS (SELECT 1 FROM identity_user u WHERE u.id = r.user_id)
```

…and the same for `identity_password_reset_request`.

**Why it implements no Domain port**, which is the interesting decision and needs its own docblock:

*"Does this row's user still exist?"* is **not a domain question**. No use case asks it, no aggregate
can answer it, and there is no business rule that depends on the answer. It is a question about
**storage integrity** — precisely the thing ADR-0009 decision 4 declined to delegate to a foreign
key and made "the application's job". Modelling it as a port would put a cross-aggregate `JOIN` into
the Domain's vocabulary and would give a future reader licence to add one to a repository, which is
the door this design is closing rather than opening.

The counter-argument, stated: an Infrastructure class with raw SQL and no interface is untestable
through a seam and unswappable. Accepted — it is exercised against the real test database like every
other adapter (AC-25), and there is nothing to swap it for.

- **`NOT EXISTS`, not `NOT IN`.** `NOT IN` has surprising NULL semantics and plans worse; `user_id`
  is `NOT NULL` so the semantics are moot today, but the habit is not.
- **It never deletes. Its docblock says so three times, and AC-25 asserts it.** A probe that can
  delete is one refactor away from being a second, undocumented retention policy.
- It runs **after** the sweep, so it reports orphans currently present. A non-zero reading that
  persists across runs means something is *actively* creating them.
- Cost: two anti-joins per run, on tables the sweep keeps small.

### The console command — decision 6

`Infrastructure/Identity/Console/PruneChallengesCommand`, `#[AsCommand('muzbar:identity:prune-challenges')]`,
following `VerifyUserEmailCommand`'s shape.

| Option | Meaning |
|---|---|
| `--dry-run` | Compute and report every count; delete nothing; write no heartbeat (AC-18, AC-21). |
| `--limit=N` | Override the per-run cap, per table (AC-19). |

Responsibilities, in order:

1. **Acquire the run lock**: Redis `SET identity:challenge_pruning:lock <pid+start> NX EX 3600`
   through the existing `Predis\ClientInterface` service. Held → log `skipped: lock_held`, exit 0.
   Redis error → log at **warning** and **proceed** (fail-open, AC-16).
2. Invoke `PruneExpiredChallengesHandler`.
3. Run `ChallengeIntegrityProbe`; log at **warning** if either count is non-zero.
4. Emit **one** INFO line on the `pruning` channel with the full field set (AC-20).
5. Write the heartbeat unless `--dry-run` (AC-21). A Redis failure here logs at warning and does
   **not** change the exit code — the work was done.
6. Release the lock; render a `SymfonyStyle` table for a human.
7. Exit **0** on success, truncation and lock-skip; **1** only on a real failure, logged at error
   (AC-38).

**Why the lock and the heartbeat live in the command rather than the handler:** both are
infrastructure state (Redis), and the Application layer may not reach it. The handler stays a pure
function of its ports, which is what makes it testable without Redis at all.

**No `symfony/lock`.** It would be a new package for a property idempotency already provides, and its
Redis store would need its own configuration. `pg_try_advisory_lock` was the other candidate — same
connection, no new dependency, released automatically on disconnect — and is a genuinely defensible
alternative; it is declined because it would put a Postgres-specific concurrency primitive in the
adapter for a property that is explicitly not a correctness requirement. **Flagged as the most
defensible place to disagree.**

### Async / schedule — decision 6, argued

**`symfony/scheduler` is not installed.** Verified in `src/composer.json`: `symfony/messenger` and
`symfony/doctrine-messenger` are present; `symfony/scheduler` is not. So this is an addition, not a
usage.

**What adding it would actually cost, concretely:**

- A new Composer dependency, and a bundle in **every kernel that boots** — `app`, `messenger-worker`,
  every console command, CI and `docker build`. CLAUDE.md's rule applies: *"when you add a required
  input to the boot path, enumerate every context that boots, not every context that serves
  traffic."*
- A **new Messenger transport** and a **new Compose service** running
  `messenger:consume scheduler_<name>` — because Scheduler is a transport, and a transport needs a
  consumer. Folding it into the existing `messenger-worker` is worse, not better: one consumer
  draining both would let a slow SMTP send delay a sweep and vice versa.
- **A second daemon on a system that cannot see the first one.** ADR-0010's amendment is unambiguous
  and was confirmed empirically: *a stopped worker looks exactly like a healthy system*. A stopped
  *scheduler* is more invisible still, because nothing and nobody waits on its output.
- **`pcntl` is absent from the image**, so `messenger:consume` cannot shut down gracefully; every
  deploy kills it mid-handle. For mail that costs a delayed message; for a schedule it costs a
  missed or replayed tick with no observability either way.
- **And the finding that settles it.** `.github/workflows/deploy.yml` runs `docker compose pull app`
  and `docker compose up -d app nginx`. **It never restarts `messenger-worker`.** The worker on the
  box is therefore running whatever image it last had. A scheduler container added today inherits
  exactly that gap — and a scheduler silently running an *old retention policy* is a worse failure
  than a worker sending an old mail template, because it deletes data according to a rule nobody
  currently believes is in force.

**What cron costs:** one line in a runbook and one line in a crontab, outside the repository and
therefore invisible to `git` — a real downside, mitigated by AC-39 putting the exact line in
`docs/infrastructure.md` and by AC-24 making its absence detectable from inside the application.

**Recommendation: ship the console command now and drive it with host cron.** The command is needed
under *either* option; the only question is the trigger, and the right trigger is the one with the
smallest new failure surface. Scheduler's real value — typed recurring messages, retries, a failure
transport — is value this job cannot use: it has no payload, no consumer waiting on a result, and
retry semantics it gets for free from being idempotent and running again in an hour.

**On Constitution §3.** The Scheduling row reads *"Symfony Scheduler (30-day ad lifecycle)"*. The
parenthetical scopes it to the feature that needs in-app scheduling, and Phase 2's ad lifecycle
genuinely does — it must send a warning email three days before expiry, which is a message with a
payload, a recipient and a delivery guarantee. **This slice defers to that slice rather than picking
a rival technology**, and ADR-0012 must say that in those words. If the owner reads §3 as binding on
all recurring work, the ADR instead records a scoped deviation with a trigger to revisit — either
way it is written down rather than inferred.

**The concrete answer to "which container runs the schedule, and what happens when it is down":**

| | Recommended (cron) | Rejected (Scheduler) |
|---|---|---|
| Who triggers | the VDS host's `cron` | a new `scheduler` Compose service |
| What runs the work | `docker compose exec -T app php bin/console muzbar:identity:prune-challenges` — inside the **`app`** container, which the deploy *does* update | the scheduler container, which the deploy currently would **not** update |
| If the runner is down | `exec` fails, non-zero, cron records it; the backlog grows and is reported by `/health/ready` | nothing runs, nothing fails, `/health/ready` stays 200 |
| If the trigger is down | nothing runs; detected by the backlog + `stale` flag within 3 h (AC-22, AC-24) | identical |

**Schedule:** `17 * * * *` — hourly, at an off-the-hour minute so it never coincides with every other
cron on the box. Hourly rather than daily because it keeps the backlog small enough that a stalled
job is detectable within hours; not more often because the windows are measured in days and nothing
needs it.

### Observability — decision 7

The design problem stated plainly: **a pruning job that silently stops is indistinguishable from a
pruning job with nothing to do.** Three mechanisms, in order of how much they are worth:

1. **The backlog (primary).** `countExpiredBefore()` is measured *before* each sweep and reported. In
   a healthy system it is ~0 after every run. If the job stops, it grows monotonically. **This cannot
   be faked by a job that runs and does nothing, and it survives Redis being flushed** — which is
   exactly why it, and not the heartbeat, is the signal to trust (AC-24).
2. **The heartbeat (secondary).** `identity:challenge_pruning:last_run`, an ISO-8601 instant from the
   `Clock`, no TTL. It distinguishes "the job has not run" from "the job ran and had nothing to do"
   *early*, before a backlog has had time to accumulate.
3. **One log line per run, always** (AC-20), on a dedicated `pruning` Monolog channel, at INFO,
   including the all-zeros case. The line **is** the record that a run happened; the deletion count
   is only one of its fields.

`/health/ready` gains a `jobs.challenge_pruning` object in its body: `last_run`, `age_seconds`,
`overdue_verification`, `overdue_reset`, `stale`. **It does not touch the status code** (AC-23), and
that restraint is a decision:

- Readiness answers *"should traffic come to this instance?"*. A housekeeping job that stopped is not
  a reason to stop serving traffic, and a probe that 503s over it would turn a hygiene problem into
  an outage — with Docker restarting a perfectly healthy container in a loop.
- It also keeps ADR-0010's genuinely open question (teaching `/health/ready` about queue depth) open
  and separate, rather than half-answering it in a slice about deleting rows.

`stale` is `age_seconds > 10800` — **three** missed hourly runs rather than two, so a single skipped
run (a lock held by a long first sweep, a deploy restart, a clock nudge) is not an alarm.

**What this does not do, stated so it is not mistaken for done:** nothing *alerts*. The signal is
visible to anyone who looks and invisible to everyone who does not. That is Sentry's job and it
remains the outstanding `devops` item — for the fourth slice running, and for the first time on work
whose only failure symptom is silence.

### DI wiring

**No new port alias** — the four new methods land on ports that are already bound. `ChallengeIntegrityProbe`
and `PruneChallengesCommand` are autowired by the `App\` resource block; `PruneChallengesCommand` is
autoconfigured as a console command by the `#[AsCommand]` attribute. `Predis\ClientInterface` is
already aliased in `services.yaml`.

One config change: `config/packages/monolog.yaml` gains a `pruning` channel. Everything else is
untouched.

---

## Interface boundary & input contract

**There is no HTTP surface.** This slice adds no route, no form, no template, and nothing anonymous
or authenticated. The only public-facing change is additive fields in `/health/ready`'s existing JSON
body, which must remain reachable and must keep its status semantics (AC-23).

**Console contract**

```
muzbar:identity:prune-challenges [--dry-run] [--limit=N]

  --dry-run           report only; delete nothing; write no heartbeat
  --limit=N           per-table cap on rows deleted this run (positive integer)

exit 0   success, truncated run, or lock-skip
exit 1   a real failure (database unreachable, an unexpected exception) — logged at error
```

`--limit` is validated at the boundary: a non-numeric or non-positive value is refused with exit 1
and a message, **before** the handler is invoked. Untrusted input crosses a validation boundary
before reaching the Domain (Constitution §8) — and a CLI argument is untrusted input even when the
only person who can supply it is the operator.

**Application contract**

```
PruneExpiredChallengesHandler::__invoke(PruneExpiredChallenges): ChallengePruningReport
    throws nothing of its own
```

The handler declares no exceptions because it has no domain failure to report. Infrastructure
failures propagate; the command converts them into exit 1 and a log line.

**`/health/ready` body addition** (shape pinned so a test can assert it):

```json
"jobs": {
  "challenge_pruning": {
    "last_run": "2026-07-31T09:17:00+00:00",
    "age_seconds": 1380,
    "overdue_verification": 0,
    "overdue_reset": 0,
    "stale": false
  }
}
```

`last_run` is `null` and `age_seconds` is `null` when the key is absent; `stale` is then `true`.

---

## Data & migrations

One migration. Purely additive, **index-only**, no column and no table.

```sql
CREATE INDEX idx_identity_email_verification_request_expires_at
    ON identity_email_verification_request (expires_at);

CREATE INDEX idx_identity_password_reset_request_expires_at
    ON identity_password_reset_request (expires_at);
```

- **Why these indexes are needed now when slice 3 explicitly declined one.** That migration's
  docblock says: *"There is no index on `expires_at`. Expiry is never a query predicate … The pruning
  job will want one; it can add it, with a caller to justify it."* This is that caller, and the
  justification is stronger than "the tables might get big": **batching multiplies the cost of a
  sequential scan by the number of batches.** A fifty-batch run without the index is fifty full table
  scans; with it, fifty range scans on the left edge of a B-tree, each touching only the rows it is
  about to delete.
- **They are declared in the migration only, not in the XML mapping.** This needs a deliberate
  choice and a comment either way: adding `<index name="…" columns="expires_at"/>` to both mapping
  files keeps `make migration.make` diffs clean forever, which is the same reasoning ADR-0009
  decision 4 used against a hand-added FK. **Recommendation: add them to the mapping too**, in the
  same commit, so the schema and the mapping never disagree — it costs two lines and removes a
  permanent source of diff noise. *(The header of this plan lists the mappings as unchanged; this is
  the one exception, and it is called out here rather than left to be discovered.)*
- **Not `CREATE INDEX CONCURRENTLY`.** Both tables are small today, so the brief `SHARE` lock is
  milliseconds. Worth knowing for later: `CONCURRENTLY` cannot run inside a transaction, so a
  Doctrine migration using it must declare `isTransactional(): false` — a real footgun the day a
  table is large enough to need it.
- **No foreign key is added** (AC-28). ADR-0009 decision 4 stands.
- `down()` drops exactly the two indexes. This slice ships **one** migration, so `migrate prev`
  genuinely reverses it — and the round trip is run by hand, because a `down()` nobody has executed
  is a comment.
- **No backfill and no data migration.** Every existing row already has the `expires_at` the sweep
  reads.

**Deploy-order note:** migrations run *after* the new image is up. Between those two moments the new
command exists and the indexes do not. The sweep is still **correct** — the predicate does not depend
on an index — merely slow, and the first run is a manual, rehearsed one anyway (AC-40).

---

## Test plan

**Domain unit (no kernel, `tests/Unit/Domain/Identity/Entity/`)** — added to the two existing
aggregate test classes rather than to new files, because they are the same units.

- `EmailVerificationRequest::retentionThreshold()` and `PasswordResetRequest::retentionThreshold()`:
  exact instants for a known `$now`; UTC preserved; whole seconds preserved.
- The two constants have their **exact** values (AC-1) — asserted against the literal from the
  feature spec, **not** against the constant itself, which would be a tautology.
- **AC-3's inversion test**, with a failure message naming the reason. This is the one test in the
  slice whose job is to make a *future refactor* fail.
- A live request built through `issue()` is never before its own retention threshold, for any `$now`
  — the arithmetic form of I-26, provable without a database.

**Application / Integration (real `muzbar_test`, DAMA rollback, `tests/Integration/Identity/`)**

- Both adapters' `countExpiredBefore()` / `deleteExpiredBefore()`:
  - **both sides of the boundary** — `threshold − 1 s` deleted, `threshold` and `threshold + 1 s`
    kept (AC-4). One-sided assertions prove nothing about the operator.
  - a **live** row is never deleted (AC-5); a **redeemed** row inside the window is never deleted
    (AC-6); an **invalidated but unexpired** reset row is never deleted (AC-7).
  - `$limit` is honoured: insert 5 overdue, limit 2, expect exactly 2 deleted and 3 remaining, and
    assert the **return value equals the observed row delta** rather than a literal.
  - a row written with raw SQL and a corrupt `token_hash` is deleted without error (AC-17).
  - a row whose `expires_at − issued_at` does not match the current `LIFETIME_SECONDS` is judged by
    its stored `expires_at` (AC-9).
- `ChallengeIntegrityProbe`: a hand-inserted orphan is counted **and still present afterwards**
  (AC-25); an overdue orphan is removed by the ordinary sweep (AC-26).
- `PruneExpiredChallengesHandler` with `FrozenClock`: one `now()` pins both thresholds (AC-10);
  batching terminates on a short batch; `MAX_BATCHES_PER_TABLE` produces `truncated: true` and a
  second invocation drains the remainder (AC-14); `dryRun` deletes nothing while reporting the same
  counts (AC-18); a run over an empty database returns an all-zero report and does not throw
  (AC-13).
- **Foundry factories are reused, not written.** `EmailVerificationRequestFactory` and
  `PasswordResetRequestFactory` already exist and already go through `issue()` via
  `instantiateWith()`. They need one new state each — `expiredLongAgo()` / an explicit `issuedAt()`
  far enough back — and **nothing may construct a row around the aggregate's named constructor** to
  fabricate an impossible `expires_at`. Where a genuinely impossible row is required (AC-9, AC-17),
  it is written with **raw SQL** and the test says why, because that is honest about being outside
  the model rather than quietly widening the factory.

**Functional (`tests/Functional/Identity/`)**

- The command via `CommandTester`: exit 0 on a clean run; exit 0 with `skipped: lock_held` when the
  lock key is pre-set (AC-16); the single log line with every field present, captured with the
  existing `RecordingLogger` (AC-20); the heartbeat written on a real run and **not** on `--dry-run`
  (AC-21); a rejected `--limit=0`.
- `/health/ready`: the new body section is present and correctly shaped; **200 with an absent
  heartbeat, 200 with an ancient one, 200 with a large backlog** (AC-23); `stale` flips at the
  3-hour boundary — asserted **both sides**.
- **AC-24's distinguishability test**, written as one test with two phases: nothing overdue →
  `overdue_* === 0`; insert overdue rows, do **not** sweep → `overdue_* > 0`. The assertion is the
  whole slice's observability claim, so it must be a single readable test rather than two that could
  drift.
- **AC-6 end-to-end:** issue a reset, redeem it, run a sweep, then replay the link — and assert the
  response is the invalid-link one *and* that the log recorded `PasswordResetLinkAlreadyUsed` rather
  than `PasswordResetRequestNotFound`. Asserting only the HTTP response would pass either way, which
  would make the test a comment.

**Test-environment gotchas to honour**

- **DAMA rolls back Postgres, not Redis** — and this slice adds **two** more Redis keys. The run
  lock and the heartbeat survive between tests. Add a `ClearsPruningState` trait (or extend
  `ClearsRateLimiters`; if extended, **rename it**, because a trait named for rate limiters that also
  clears job state is the kind of drift CLAUDE.md warns about). **AC-42's `make test` twice in a row
  is the cheap proof.**
- **`FrozenClock` honours the `Clock` contract** (UTC, whole seconds). Time is advanced by
  constructing a second `FrozenClock`, never by `sleep()`. Note the interaction with
  `MAX_RUN_SECONDS`: a frozen clock never reaches the deadline, so the wall-clock cap is **not**
  testable with it and `MAX_BATCHES_PER_TABLE` is the cap the tests exercise. Say so in the test's
  docblock rather than letting a reader believe both caps are covered — *a docblock claiming
  coverage the assertion cannot deliver is the same defect one level up.*
- **Swapping an adapter in a test needs two things and each fails silently alone**: target the
  **concrete class's** service id, never the port alias, and call `$client->disableReboot()` first.
  Relevant if a throwing repository is used to assert exit 1.
- **A repository fetched in `setUp()` serves from Doctrine's identity map.** After a sweep, assertions
  about which rows survive must come from a **fresh** repository or a raw `COUNT`, or a deleted row
  can still be handed back from memory.
- **Every value here is asserted against the feature spec, never against observed behaviour.** The
  windows, the batch size, the caps and the staleness threshold come from the ACs. A test written by
  running the sweeper and recording what survived has no source of truth independent of the sweeper.

**Infrastructure assertions (`tests/Integration/Identity/`)**

- `EXPLAIN` shows an Index Scan on each new index, for the count and for the delete's selection
  subquery, on both tables (AC-37).
- The SQL logger over one full run shows **no write** to `identity_user` and no read other than the
  probe (AC-8).
- `symfony/scheduler` is absent from `composer.json` and `composer.lock` (AC-35).
- No `Challenge` type exists (AC-32) and no SQL/DQL string appears under `Application/` or `Domain/`
  (AC-34) — both greps, both cheap, both guarding a decision that would otherwise erode quietly.

---

## Risks / open questions

1. **Constitution §3 names Symfony Scheduler and this slice does not install it.** The plan reads the
   row's parenthetical as scoping it to the ad lifecycle, so deferring ≠ contradicting.
   **Recommendation: adopt, and make ADR-0012 state the reading explicitly** — a Constitution row and
   a shipped slice that appear to disagree, with the reconciliation living only in someone's head, is
   how documentation starts lying.
2. **`deploy.yml` never restarts `messenger-worker`** (`pull app`, `up -d app nginx`). Found while
   costing decision 6. It means the worker on the box runs a stale image, which is a live bug today —
   and it is the strongest single argument against adding a second daemon. **Recommendation: file it
   as a `devops` item now**, independently of this slice, and fix it before any future slice adds a
   `scheduler` service.
3. **Nothing alerts.** AC-20 to AC-24 make failure visible; noticing it still needs a human.
   **Recommendation: Sentry, still, and this slice is the strongest argument yet** — it is the first
   piece of work whose only symptom of failure is the absence of a symptom.
4. **The retention windows have no backstop.** If a window is too short, recovery means a database
   dump, and dumps are unscheduled. **Recommendation: decide the dump schedule alongside these
   windows**, in `docs/infrastructure.md`, in this slice's documentation commit.
5. **The orphan probe joins two aggregates' tables in SQL.** Infrastructure-only, port-less,
   read-only — but a reader could take it as precedent. **Recommendation: keep, with a docblock that
   closes the door explicitly**, and flag it to the reviewer as a deliberate boundary crossing.
6. **`/health/ready` gains two `COUNT` queries per probe.** Bounded by the new indexes and a
   healthcheck-frequency caller. **Recommendation: accept**; if it hurts, cache the counts in Redis
   with a short TTL rather than moving to a second endpoint.
7. **`messenger_messages` and the `failed` queue grow without bound and this slice does not touch
   them.** **Recommendation: add a `devops` roadmap item.** Naming the boundary is what stops a
   future reader assuming `identity-challenge-pruning` covered it.
8. **The run lock is fail-open and deliberately not a correctness mechanism.** A reviewer may
   reasonably prefer `pg_try_advisory_lock` — same connection, no fail-open ambiguity, no dependency
   on Redis. **Recommendation: keep the Redis lock, and flag this as the designated place to
   disagree.** If the counter-case is made, the change is contained to the console command.
9. **This slice writes a `DELETE` into a system that has never deleted anything.** The mitigations
   are structural (I-26 makes deleting a live row arithmetically impossible), procedural (AC-40's
   rehearsed first run, `--dry-run` first) and testable (AC-4's two-sided boundary). Stated as a risk
   anyway, because "we have never deleted before" is exactly the condition under which a bad delete
   goes unnoticed. **Recommendation: `/verify` for this slice reads the two adapter methods by hand,
   in addition to the standard reviewer pass.**
10. **`symfony/uid`'s UUIDv7 keys make deletion cheap, and that was designed in.** Both adapters'
    `nextIdentity()` docblocks already predicted this job: *"the pruning job owed later will delete
    from the same index. Time-ordered keys mean those deletions clear whole left-hand pages rather
    than punching holes through the middle of the tree."* Recorded here as a prediction that came
    good, and as the reason `ORDER BY expires_at` in the batch subquery is worth the words.
</content>
