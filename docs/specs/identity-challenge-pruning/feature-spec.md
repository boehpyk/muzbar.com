# Feature Spec: identity-challenge-pruning

> The *what & why*. Disposable — this dies once the feature ships and its behaviour lives in tests +
> code. Make it **executable** (measurable, enumerated), not pretty. Must not contradict the
> [Constitution](../../constitution.md) or an accepted ADR.

- **Feature:** `identity-challenge-pruning` — slice **4 of 7** of the `Identity` context
  *(the roadmap's Identity list has grown from five to seven since slice 3 was written: this slice
  and `identity-password-changed-notification` were both added by ADR-0011's consequences)*
- **Bounded context(s):** `Identity` only. Nothing else is created or touched.
- **Related PRD items:** none directly — this is the **debt-paying** slice. It discharges the
  pruning obligation slices 2 and 3 both recorded, and Constitution §8 (GDPR applies to EU users) is
  the reason it is a policy decision rather than a disk-space chore.
- **Governing ADRs:** [ADR-0007](../../adr/0007-persistence-conventions-for-domain-aggregates.md),
  [ADR-0008](../../adr/0008-domain-events-recorded-on-the-aggregate.md),
  [ADR-0009](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) — **especially its
  decision 4**, whose orphan-row clause this slice exists to answer —
  [ADR-0010](../../adr/0010-event-delivery-and-transactional-mail.md) and
  [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md)
- **Status:** **Approved — signed off 2026-08-01.** All eleven decisions in the
  [technical plan](./technical-plan.md) §*Decisions needing sign-off* are adopted as recommended.
  The four that gate **T0 (write ADR-0012)** were decided explicitly: **1** (prune on `expires_at`
  alone), **3** (set-based `DELETE` behind a port method), **6** (host cron, with Constitution §3
  read as *scoped* by its own parenthetical rather than deviated from), **10** (orphans counted by a
  read-only probe, swept on the ordinary schedule, no FK, erasure specified not built). T0 is
  unblocked.
- **Date:** 2026-07-31 (drafted) · 2026-08-01 (approved)

> **The one thing to resolve before reading anything else: the two tables do not agree about what
> "dead" means, and this slice's answer is to stop asking.**
>
> `EmailVerificationRequest` absorbs replays, lets several challenges coexist, and has `redeemed_at`
> but **no** `invalidated_at`. `PasswordResetRequest` refuses replays, invalidates outstanding
> challenges on reissue, has **both** terminal columns, and carries a *staleness* notion that lives
> on a **different aggregate** (`User.passwordChangedAt`). Those are four of the deliberate
> inversions [ADR-0011](../../adr/0011-password-reset-challenges-modelled-in-the-domain.md) decision
> 9 records, and CLAUDE.md is explicit that a shared abstraction over them is a latent security bug
> rather than a tidy-up.
>
> A sweep predicate built out of "dead" would be exactly that shared abstraction, one level down —
> the `Challenge` base class rejected in slice 3, re-derived in SQL where no unit test can reach it.
> **So the sweep does not use "dead" at all.** It uses `expires_at`: the one column both tables
> define identically, both aggregates derive identically (`issuedAt + LIFETIME_SECONDS`, invariants
> I-8 and I-15), and which is a *ceiling* on every other reason a row could be finished with — a
> redeemed, invalidated or stale row is also, within at most its own lifetime, an expired one. The
> shared thing is **a column with one meaning**, not a concept with two.
>
> This is argued in full, including what a wrong guess costs in each direction, in the technical
> plan §*The predicate*.

> **What this slice is for, beyond the feature.**
>
> Slice 1 taught *an aggregate, a value object, a port*. Slice 2 taught *a second aggregate and the
> eventual consistency it buys and costs*. Slice 3 taught *the same shape with the opposite
> semantics*. This slice teaches the one every DDD codebase eventually gets wrong: **where the
> boundary sits when the operation is not a state transition.**
>
> Deleting a row is not something an aggregate does. It is the aggregate ceasing to exist, and an
> object has no invariants about its own non-existence. The DDD-honest-*looking* move — load each
> row, call a method on it, delete it — is the operationally naive one, and it is naive for a reason
> worth internalising rather than memorising. The rule this slice extracts and writes down is:
> **put in the Domain the part that can be wrong.** Here that is *which rows get selected*, and that
> is exactly the part that stays pure PHP.

---

## Ubiquitous language (this slice)

| Term | Means | Not to be confused with |
|---|---|---|
| **Challenge** | The umbrella word, used **only in prose**, for the two existing aggregates that model an issued, expiring, single-use link: `EmailVerificationRequest` and `PasswordResetRequest`. It names a *family resemblance* for humans. | A type. **There is no `Challenge` class, interface, base class, trait or port in this slice, and adding one is a defect** (AC-32). If the word ever reaches code it has stopped being prose. |
| **Retention window** | How long after a challenge **expires** its row is still worth keeping. A domain constant on each aggregate (`RETENTION_AFTER_EXPIRY_SECONDS`), independently chosen: **7 days** for verification, **30 days** for reset. | The challenge's **lifetime** (`LIFETIME_SECONDS`: 24 h / 1 h) — how long the *link works*. Retention is about the question the row answers *after* it stops working, which is why the ordering between the two tables comes out inverted (AC-3). |
| **Retention threshold** | The instant `now − RETENTION_AFTER_EXPIRY_SECONDS`, computed by a pure static on each aggregate. A row is prunable when its `expires_at` is **strictly before** it. | Configuration. It is derived inside the method, never a parameter — invariant I-15's reasoning one level up: a window a caller may supply is a default, not a rule. |
| **Overdue row** | A row whose `expires_at` is strictly before that table's retention threshold. The sweep's entire selection criterion. | A **dead** row. Most dead rows are not yet overdue, and keeping them is the point (AC-6). |
| **Live challenge** | Not redeemed, not invalidated, not expired — slice 3's `isLiveAt()`. | An overdue row. **A live challenge can never be overdue**, because `expires_at ≥ now > threshold`. That implication is the slice's central safety property (I-26, AC-5). |
| **Sweep** | One set-based `DELETE` of at most one batch of overdue rows from **one** table, performed by the Infrastructure adapter behind a port method. | The **run**. |
| **Run** | One invocation of `muzbar:identity:prune-challenges`: one `now()`, two thresholds, a batched sweep per table, a read-only orphan probe, one log line, one heartbeat write. | A "job" in the Messenger sense. Nothing is queued, nothing is retried, no message type exists. |
| **Truncated run** | A run that hit its per-run batch or wall-clock cap with work remaining. Reports `truncated: true` and **exits 0** — the next run continues. | A failed run. A capped run is the expected shape of the *first* run, and of any run after an outage. |
| **Orphan row** | A challenge row whose `user_id` matches no `identity_user` row — possible by construction because [ADR-0009 decision 4](../../adr/0009-email-verification-tokens-modelled-in-the-domain.md) declines a foreign key. **Zero exist today: nothing deletes users.** | A dangling reference the sweep hunts for. It does not. Orphan-ness is **not** a prune criterion — an orphan is deleted on the ordinary `expires_at` schedule like anything else (AC-26) and is separately **counted and reported, never deleted early** (AC-25). |
| **Backlog** | The number of overdue rows present *before* a sweep runs. The observability primitive: **zero backlog means "nothing to do"; a growing backlog means "the job stopped".** | The table's row count. In a healthy table almost no rows are overdue. |
| **Heartbeat** | The Redis key `identity:challenge_pruning:last_run`, holding the `Clock`'s instant for the last completed run. The belt to the backlog's braces. | The **run lock**, a different key with a different purpose (AC-16). |
| **Erasure** | The future GDPR right-to-be-forgotten path: immediate, complete deletion of one person's data on request. **Specified here, built nowhere.** | Pruning. Retention windows do not apply to an erasure request, and a design that expects "the pruner will get to it eventually" has not implemented erasure (AC-29). |

---

## User story

As the **operator of muzbar**, I want **expired verification and reset challenges deleted on a
schedule I can see working**, so that **two tables which otherwise grow forever — holding the
digests of dead secrets and a dated record of who asked to recover their account — stop being a
liability I have to remember about.**

And, as **muzbar**, I want **a stopped pruning job to look different from a pruning job with nothing
to do**, so that **the first recurring background process in this system is not also the first one
whose entire failure mode is silence.** Every prior slice has written "Sentry is overdue" into its
risks; this slice ships work whose only symptom of failure is the *absence* of a symptom, so it must
carry its own signal rather than adding a fourth entry to that list.

Secondary and ranked (Constitution §2): as the **developer**, I want this slice to teach the fourth
DDD lesson honestly — **that an aggregate governs its state transitions and not its own
non-existence**, and that when two models disagree the right move is sometimes to find the one thing
they do *not* disagree about, rather than to invent a third model that averages them.

---

## In scope

**Domain (`Identity`)** — additive only; no new type of any kind.

- One new public constant per challenge aggregate: `RETENTION_AFTER_EXPIRY_SECONDS`
  (`EmailVerificationRequest` = 604 800; `PasswordResetRequest` = 2 592 000).
- One new pure static per challenge aggregate:
  `retentionThreshold(\DateTimeImmutable $now): \DateTimeImmutable`.
- Two new methods on **each** existing repository port — `countExpiredBefore()` and
  `deleteExpiredBefore()` — declared separately, named for their own aggregate. This discharges the
  promise both port docblocks already make: *"the pruning job owed later will add its own method
  when it is written, with its own justification."*
- **No new aggregate. No new value object. No new domain event. No new domain exception.** Every one
  of those absences is argued in the technical plan rather than left to be noticed.

**Application**

- `PruneExpiredChallenges` command + `PruneExpiredChallengesHandler`: one `now()`, two thresholds,
  the batching loop, the per-run caps, and a `ChallengePruningReport` result.
- The **operational** constants — batch size and per-run caps — live here, *not* in the Domain. The
  technical plan draws that line explicitly and says why.

**Infrastructure**

- `countExpiredBefore()` / `deleteExpiredBefore()` on both Doctrine adapters: set-based DQL, **no
  hydration**.
- `ChallengeIntegrityProbe` — a **read-only** DBAL anti-join counting orphan rows per table. It
  implements no Domain port, and that is a decision rather than a shortcut.
- `muzbar:identity:prune-challenges` console command, with `--dry-run` and `--limit`, the Redis run
  lock, the heartbeat write and the single structured log line.
- **One additive migration**: an index on `expires_at` for each table. Nothing else changes.
- A **reporting-only** `jobs.challenge_pruning` section in `/health/ready`'s JSON body, which
  **cannot change its status code**.
- The cron line, the first-run procedure and the "what to check when it stops" entry in
  `docs/infrastructure.md`.

**Not code, but in scope**

- **ADR-0012**, plus dated amendments to **ADR-0009** (decision 4's orphan clause) and **ADR-0011**
  (its "the pruning debt now spans two tables" consequence).
- The GDPR-erasure **specification** — the ordering rule and the retention carve-out — written where
  the future slice will actually find it.

---

## Non-goals (explicit — hold the line)

| Deliberately excluded | Belongs to |
|---|---|
| **Installing `symfony/scheduler`.** The Constitution locks it as *the* scheduling technology and names the 30-day ad lifecycle as its use; this slice defers to that slice rather than picking a rival, and ADR-0012 says so out loud | Phase 2 (`Listing` lifecycle) |
| **A user-deletion path of any kind.** There is none today, so there are currently **zero** orphan rows. This slice specifies what erasure must do and builds none of it | a GDPR-erasure slice before public launch |
| **Deleting a dead-but-not-yet-overdue row early.** Redeemed and invalidated rows survive their retention window on purpose — the "already used" answer and the incident-review trail are exactly what the window buys (AC-6) | — |
| **Archiving to cold storage before deletion.** A second store, a second retention policy and a second thing to forget about, for rows holding a digest and four timestamps | never, unless a compliance requirement names it |
| **Pruning `messenger_messages` or the `failed` queue.** They grow too, they are Messenger's tables with Messenger's semantics, and a job named for `Identity` challenges must not quietly acquire them (see *Risks* 7) | `devops`, and it is now a named item rather than an assumption |
| **Pruning `identity_user`.** Nothing deletes users, and a housekeeping job is not where that would start | erasure |
| **Changing `/health/ready`'s status code**, or teaching it about Messenger queue depth | still Phase 2, still ADR-0010's open question |
| **Sentry, and any actual alerting.** This slice makes the failure *visible*; nothing yet *notices* it. Fourth slice running | `devops` — see *Risks* 3 |
| **Any change to either aggregate's behaviour** — lifetimes, caps, the four inversions, the save orderings, the exception ordering. The diff on both aggregates is one constant and one static each (AC-30) | — |
| **A shared `Challenge` type, `Uuid` value object, base class or trait.** Slice 3 answered this and restated the trigger as a criterion (*"the first aggregate id outside `Identity`"*); this slice is not that, so the answer is unchanged and is not re-argued from scratch | ADR-0009's amended trigger |
| **A UI, an admin screen or a metrics dashboard** | not Phase 1 |
| **Visual design.** No user-facing surface is added at all | — |

---

## Acceptance criteria (the Definition of Done checklist)

Enumerated, measurable, each independently checkable. These are what `/verify` checks off.
**Values are pinned here on purpose** — 7 days, 30 days, batch 1000, 50 batches, 30 seconds, hourly,
a 3-hour staleness threshold. The technical plan argues each one; none may be changed here without
changing it there.

### The predicate and the retention policy

- [ ] **AC-1:** `EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS === 604800` (7 days) and
      `PasswordResetRequest::RETENTION_AFTER_EXPIRY_SECONDS === 2592000` (30 days). Both are public
      Domain constants beside the existing `LIFETIME_SECONDS`; **neither is read from config, an env
      var, a DI parameter or a command option.**
- [ ] **AC-2:** `retentionThreshold($now)` on each aggregate returns exactly `$now` minus that
      aggregate's own constant — derived inside the method, never a parameter. Asserted by a pure
      unit test with no kernel, on both aggregates.
- [ ] **AC-3 (the inversion, pinned so it cannot be "aligned"):** verification's retention window is
      **shorter** than reset's, although verification's *link* lives twenty-four times longer. A
      unit test asserts `EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS <
      PasswordResetRequest::RETENTION_AFTER_EXPIRY_SECONDS` with a failure message naming the
      reason, so a future tidy-up that equalises them fails loudly instead of passing quietly.
- [ ] **AC-4 (the boundary, both sides):** the selection is `expires_at < threshold`, **strict**. A
      row at `threshold − 1 s` is deleted; rows at exactly `threshold` and at `threshold + 1 s` are
      **kept**. Asserted on **both** tables. *Asserting one side proves nothing about which operator
      is in the code — slice 3's rule, applied again.*
- [ ] **AC-5 (the safety property — the important one):** a **live** challenge is never deleted, by
      any run, at any limit, on either table. Asserted directly, and the implication is stated at the
      call site: a live row has `expires_at ≥ now`, and `threshold < now`, so no live row can satisfy
      the predicate.
- [ ] **AC-6 (why the window exists at all):** a **redeemed** row still inside its retention window
      is not deleted, so replaying a spent reset link still produces `PasswordResetLinkAlreadyUsed`
      rather than `PasswordResetRequestNotFound`. Asserted end-to-end through the reset flow, not
      only at the row level. *Both give the visitor the same neutral response (slice 3's AC-16); the
      difference lives in the log and in an incident review, which is exactly where it is worth
      something.*
- [ ] **AC-7 (the tables' disagreement is encoded nowhere):** the strings `invalidated_at`,
      `redeemed_at`, `password_changed_at` and `email_verified_at` appear in **no** pruning
      predicate — not in either adapter method, not in either port method's contract, not in the
      handler. Asserted by reading the two adapter methods and by a test in which an **invalidated
      but unexpired** reset row survives a run.
- [ ] **AC-8:** a run issues **no query against `identity_user`** other than the read-only orphan
      probe, and writes to it never. Asserted with the SQL logger over a complete run.
- [ ] **AC-9:** the sweep stays correct across a future change to either `LIFETIME_SECONDS`, because
      it reads the **stored** `expires_at` rather than recomputing it from `issued_at`. Asserted by
      hand-writing a row whose `expires_at − issued_at` differs from the current constant and
      confirming it is judged by its stored value.

### Mechanism: batching, caps, idempotency, locking

- [ ] **AC-10:** one `Clock::now()` per run pins **both** thresholds. Asserted with `FrozenClock` and
      by reading the handler. *The wall-clock cap calls `now()` again on purpose; the two uses are
      distinguished in the code with a comment, because conflating them would let the threshold
      drift mid-run.*
- [ ] **AC-11:** the handler deletes in batches of **1000**, looping per table until a batch returns
      fewer than 1000 rows or a cap is hit. The adapter method performs **one** statement per call.
- [ ] **AC-12:** per-run caps are **50 batches per table** and **30 seconds of wall clock**. Hitting
      either sets `truncated: true` in the report and in the log line, and the command **exits 0**.
- [ ] **AC-13 (idempotent by construction):** a second run immediately after a first deletes **0**
      rows, exits 0, and still emits its log line — with every counter zero.
- [ ] **AC-14 (a capped run is resumable):** with the cap set to one batch and more rows overdue, run
      one deletes exactly one batch and reports `truncated: true`; run two drains the remainder and
      reports `truncated: false`. No row is deleted twice; none is skipped.
- [ ] **AC-15 (interruption safety):** batches commit independently, so killing a run mid-sweep
      leaves whole batches deleted and **no partial row state** — there is none to have. Argued from
      the design and asserted through AC-14's resumability rather than by killing a process, because
      a test that kills a process is a flaky test.
- [ ] **AC-16 (the run lock):** a second concurrent run finds the Redis key held, deletes nothing,
      logs `skipped: lock_held` and **exits 0**. With Redis unavailable the run **proceeds** and logs
      at warning. *The lock is politeness, not correctness — correctness comes from AC-13 — and the
      code says exactly that at the acquisition site.*
- [ ] **AC-17 (set-based, provably):** a row whose `token_hash` cannot be rehydrated into a valid
      value object is still deleted, because nothing is hydrated. Asserted by writing a corrupt row
      with raw SQL and sweeping over it. *This is the concrete cost of the load-and-delete
      alternative: one bad row would throw and stall the whole sweep, forever, silently.*
- [ ] **AC-18:** `--dry-run` reports identical counts and deletes **zero** rows. Asserted by row
      counts before and after.
- [ ] **AC-19:** `--limit=N` caps total deletions **per table** at N.

### Observability

- [ ] **AC-20:** **every** run — including one that deletes nothing — emits exactly **one** INFO log
      line on a dedicated `pruning` channel, carrying `threshold_verification`, `threshold_reset`,
      `overdue_verification`, `overdue_reset`, `deleted_verification`, `deleted_reset`,
      `orphaned_verification`, `orphaned_reset`, `batches`, `truncated`, `dry_run` and
      `duration_ms`. *The line is the heartbeat; the deletion is not.*
- [ ] **AC-21:** a completed run writes the `Clock`'s instant to the Redis key
      `identity:challenge_pruning:last_run` in ISO-8601. A `--dry-run` run does **not** write it.
- [ ] **AC-22:** `/health/ready`'s JSON body gains `jobs.challenge_pruning` with `last_run`,
      `age_seconds`, `overdue_verification`, `overdue_reset` and `stale`. `stale` is `true` when
      `age_seconds` exceeds **10800** (three missed hourly runs) or when the key is absent.
- [ ] **AC-23 (the endpoint's contract is unchanged):** `/health/ready`'s **status code is not
      affected by any of it.** With the heartbeat absent, ancient, and with a large backlog present,
      it still returns **200** as long as Postgres and Redis answer. Asserted by a test that stalls
      the marker and asserts 200. *Readiness answers "should traffic come here"; a housekeeping job
      that stopped is not a reason to take the site out of rotation, and a probe that 503s over one
      converts a hygiene problem into an outage.*
- [ ] **AC-24 (the point of the whole section):** "stopped" and "nothing to do" are **structurally
      distinguishable without trusting the job's own report.** Asserted in one test: with nothing
      overdue, `overdue_*` is 0; after inserting overdue rows and **not** running the sweep,
      `overdue_*` is non-zero. *A heartbeat alone can be written by a job that runs and does nothing;
      a backlog cannot be faked, and it survives Redis being flushed.*

### Orphan rows — the ADR-0009 decision 4 answer

- [ ] **AC-25:** `ChallengeIntegrityProbe` counts, per table, rows whose `user_id` matches no
      `identity_user` row, and **deletes nothing** — asserted by hand-inserting an orphan, confirming
      a count of 1, and confirming the row is still there afterwards.
- [ ] **AC-26:** an orphan row **past its retention window** is deleted by the ordinary sweep, with
      no special handling and no reference to its orphan status. *Orphan-ness is a state that
      resolves itself within the window; it is not a prune criterion.*
- [ ] **AC-27:** the probe's two counts appear in the run's log line (AC-20) and in
      `/health/ready`'s body (AC-22), and a non-zero count is additionally logged at **warning**.
      Today it can only mean manual database surgery or a bug.
- [ ] **AC-28:** **no foreign key is added** to either challenge table. ADR-0009 decision 4 stands
      and is *discharged*, not reversed. Asserted by grep over the new migration and by inspecting
      the live DDL.
- [ ] **AC-29 (specified, not built):** ADR-0012 and `docs/infrastructure.md` record that a future
      erasure path must (a) delete the person's rows from **both** challenge tables **before**
      deleting their `identity_user` row — so a crash leaves a user with no challenges rather than
      orphans that read as corruption — and (b) that **retention windows do not apply to an erasure
      request**, which is immediate and complete. Checked by reading both documents.

### Architecture & layering

- [ ] **AC-30 (the Domain diff, bounded):** each challenge aggregate gains **exactly one constant and
      one static method**, and nothing else — no property, no instance method, no import, no
      behaviour change. Checked by reading the two-file diff.
- [ ] **AC-31 (no new type):** this slice adds **no aggregate, no value object, no domain event and
      no domain exception.** Each absence is argued in the technical plan; the check is that
      `src/src/Domain/Identity/{Entity,ValueObject,Event,Exception}/` gains no file.
- [ ] **AC-32 (no shared abstraction):** there is no class, interface, trait, port, enum or DI-tagged
      collection spanning the two challenge aggregates. The two port methods are declared separately
      on the two existing ports and named for their own aggregate. Asserted by grep for a `Challenge`
      type and by reading both ports.
- [ ] **AC-33 (Domain purity):** `grep -rE '^use (Symfony|Doctrine)\\' src/src/Domain/` still returns
      nothing, and Deptrac is green under `--fail-on-uncovered`.
- [ ] **AC-34 (the layer split, made checkable):** no SQL or DQL string appears anywhere under
      `src/src/Application/` or `src/src/Domain/`; the retention constants and the threshold
      arithmetic appear **only** under `src/src/Domain/`; the batch size and the per-run caps appear
      **only** under `src/src/Application/`. Asserted by grep.
- [ ] **AC-35:** `symfony/scheduler` is not an **installed package** — absent from `composer.json`'s
      `require`, and absent from `composer.lock`'s `packages` / `packages-dev` **lists**; **no new
      Compose service, no new Messenger transport and no new environment variable** is introduced.
      Asserted by inspecting the lock's package list (or `composer show`) and by diffing both compose
      files. **Not by grep** — corrected 2026-08-01: `grep symfony/scheduler src/composer.lock`
      already returns two hits on a clean checkout, both inside `symfony/framework-bundle`'s
      `conflict` constraints, so the grep form of this criterion **fails before any code is
      written**. That is the mirror image of the repo's no-assertion-that-cannot-fail rule: an
      assertion that cannot pass is just as useless, and it would have been "fixed" by deleting it. *ADR-0010's amendment makes a new
      boot-path env var a four-place change — `app`, `messenger-worker`, CI and the image build —
      and the cheapest way to get that right is not to add one.*

### Schema

- [ ] **AC-36:** **one** migration, purely additive:
      `idx_identity_email_verification_request_expires_at` and
      `idx_identity_password_reset_request_expires_at`. No column added, dropped or altered; no
      existing index touched; `down()` drops exactly the two new indexes; `migrate prev` then
      `migrate` round-trips cleanly and is run by hand rather than assumed.
- [ ] **AC-37:** `EXPLAIN` shows an **Index Scan** on each new index for (a) the sweep's selection
      subquery and (b) the overdue count, on both tables. *The justification for these indexes is the
      **batching**, not the table size: without them a fifty-batch run is fifty sequential scans.*

### Operations & gates

- [ ] **AC-38:** the command is `muzbar:identity:prune-challenges`. It exits **0** on success, on a
      truncated run and on a lock-skip; it exits **1** only on a genuine failure such as Postgres
      being unreachable, and logs at error when it does.
- [ ] **AC-39:** `docs/infrastructure.md` carries the exact cron line (hourly, at an off-the-hour
      minute), the container it runs against, the first-run procedure, and **what to check when the
      backlog grows** — as a runbook entry, not a sentence buried in a paragraph.
- [ ] **AC-40 (the first run is rehearsed, not discovered):** before cron is enabled, the command is
      run once with `--dry-run` and once with an explicit small `--limit`, and both outputs are
      recorded in the verification notes. *The first real run is the only one that can meet an
      unbounded backlog.*
- [ ] **AC-41:** `make check` is green: php-cs-fixer clean, PHPStan **max** zero errors, Deptrac zero
      violations and zero uncovered, PHPUnit green with new Domain unit tests **and**
      Integration/Functional tests.
- [ ] **AC-42 (the gate `make check` cannot give you):** `make test` passes **twice in a row**. *This
      slice adds two more pieces of Redis state that DAMA does not roll back — the run lock and the
      heartbeat — and a second run that fails is the classic symptom.*

---

## Failure contract

*"No mutation"* means no row deleted and no heartbeat written.

| Condition | Expected behaviour |
|---|---|
| Nothing is overdue | A full run happens anyway: both counts are 0, the log line is emitted with zeros, the heartbeat **is** written, exit 0. **A quiet run is still a run and must be visible as one** — that is the entire observability design. |
| Overdue rows exceed the per-run caps | Deletes up to the cap, reports `truncated: true`, exits **0**. The next run continues. Nothing is lost and nothing repeats. |
| A second run starts while one is in progress | Redis `SET NX` fails → the second run deletes nothing, logs `skipped: lock_held`, exits **0**. |
| Redis unavailable | The lock cannot be taken and the heartbeat cannot be written. The run **proceeds** and does its work, logging at **warning** for each. *Fail-open is deliberate: correctness comes from idempotency, not from the lock, and housekeeping must not stop because a cache is down.* The visible cost is that `/health/ready` then reports the pruner stale — which is precisely why the **backlog**, not the heartbeat, is the primary signal (AC-24). |
| Postgres unavailable | The command logs at **error**, deletes nothing, exits **1**. Cron carries the non-zero exit. `/health/ready` already reports Postgres down. |
| The `app` container is down when cron fires | `docker compose exec` fails; nothing runs; cron records the failure. The backlog grows and is reported by the next successful run and by `/health/ready`. |
| Cron itself is disabled, or the box's crontab is lost | **Nothing runs and nothing fails.** This is the failure this slice exists to make visible: the backlog grows monotonically and `jobs.challenge_pruning.stale` turns `true` within three hours. Nothing yet *alerts* — see *Risks* 3. |
| A challenge row's `token_hash` is corrupt and cannot rehydrate | Deleted like any other row; the sweep never hydrates an aggregate (AC-17). |
| A row is an orphan (no matching `identity_user`) | Counted by the probe, reported, logged at warning, **not deleted early**; deleted by the ordinary sweep once overdue (AC-26). |
| A row's `expires_at` is further in the future than its lifetime allows (clock skew on the writing container, or a hand-written row) | Never deleted, because the predicate compares against the stored value. Benign, and it fails **safe**: skew can only make the job delete *less*. |
| The system clock jumps backwards | The threshold moves back, so fewer rows qualify. Benign, same direction. |
| `LIFETIME_SECONDS` is changed in a future deploy | Rows written under the old lifetime keep their stored `expires_at` and are judged by it (AC-9). No backfill, no migration, no window in which old and new rows disagree. |
| A live challenge exists at the moment of a run | Untouched, unconditionally (AC-5). |
| A redeemed or invalidated challenge inside its retention window exists | Untouched (AC-6, AC-7). |
| `--dry-run` is passed | Every count is computed and reported; **no row is deleted and no heartbeat is written** (AC-18, AC-21). |
| A migration is pending when the command runs | The `expires_at` indexes are absent, so the sweep is slow but **correct** — the predicate does not depend on an index. Worth knowing because the deploy runs migrations *after* bringing the app up. |

---

## Risks / open questions

*(Each is argued in full in the [technical plan](./technical-plan.md); this is the honest list of
what could still be wrong.)*

1. **Constitution §3 names Symfony Scheduler as the scheduling technology, and this slice does not
   install it.** The plan's reading is that the row's own parenthetical — *"(30-day ad lifecycle)"* —
   scopes it to the feature that needs in-app scheduling semantics, so deferring is not the same as
   contradicting. **That reading needs sign-off, and ADR-0012 must state it explicitly rather than
   leaving a future reader to reconcile two documents.**
2. **A discovered, pre-existing gap that sharpens decision 6.** `.github/workflows/deploy.yml` runs
   `docker compose pull app` and `docker compose up -d app nginx` — **it never restarts
   `messenger-worker`.** The worker on the box therefore runs whatever image it last had. A second
   long-running container added today would inherit exactly that, and a scheduler silently running
   an old retention policy is worse than a worker silently sending old templates. **This is a
   `devops` bug that exists now, independent of this slice, and it should be filed as one.**
3. **Nothing alerts.** AC-20 to AC-24 make the failure *visible*; noticing it still requires someone
   to look. This is the fourth consecutive slice to write that sentence and the first to ship work
   whose only symptom of failure is silence. **Sentry remains the highest-value `devops` item.**
4. **The retention windows are judgement calls.** 7 days and 30 days are argued from the questions
   the rows answer after they die, not from a standard. If a support case or an incident review ever
   wants a row that has been swept, the window was too short — and the only remedy is a database
   dump. **Which is a second open question: `make db.dump` is on-demand and unscheduled
   (`docs/infrastructure.md` still lists a daily dump as a to-do), so today there is no backstop
   behind the retention window at all.** Recommend deciding the dump schedule and the windows
   together, in that document.
5. **The orphan probe reaches across an aggregate boundary in SQL.** It is deliberately Infrastructure
   only, implements no port, and never deletes — but it is still a query joining two aggregates'
   tables, and a reader could take it as precedent for putting one in a repository. Its docblock has
   to close that door explicitly.
6. **`/health/ready` grows two `COUNT` queries per probe.** Bounded by the new indexes and by the
   sweep keeping the tables small, and the probe interval is a Docker healthcheck rather than user
   traffic. If it ever hurts, the fallbacks are a short-TTL Redis cache of the two counts or a
   separate `/health/jobs` endpoint — both strictly more machinery, which is why neither is here.
7. **`messenger_messages` and the `failed` queue still grow without bound**, and this slice
   deliberately does not touch them. Naming it here is the whole mitigation.
</content>
