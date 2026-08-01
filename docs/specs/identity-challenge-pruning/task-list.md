# Task List: identity-challenge-pruning

> Ordered, small tasks in DDD canonical order. Each should be reviewable in < 5 minutes and is one
> commit on `feature/identity-challenge-pruning`. Run `make check` before each commit. Check off as
> you go.

**UNBLOCKED — all eleven decisions signed off 2026-08-01**, each adopted as recommended in the
[technical plan](./technical-plan.md) §*Decisions needing sign-off*. T0 may start; nothing below T0
may start before T0 lands.

**Rule for this slice:** tasks T1–T4 must not contain the strings `use Symfony\` or `use Doctrine\`,
and T5–T8 must not contain a SQL or DQL string. `make deptrac` proves the first under
`--fail-on-uncovered`; AC-34's grep proves the second.

**Second rule, specific to this slice:** the two aggregates get an **identically shaped** constant
and static with **deliberately different values**. Each docblock must name its twin and say *why the
number differs*. Slice 3 established the rule for a file that contradicts an existing one; this is
the same hazard wearing the opposite face — two files that agree in shape and disagree in value are
exactly what a future reader "aligns".

**Third rule:** this slice writes the first `DELETE` in the repository's history. Every task that
touches the predicate carries a two-sided boundary assertion or it is not done.

## Decisions & prerequisites

- [x] **T0 — DONE 2026-08-01** (`docs(identity): ADR-0012 challenge retention and recurring work`).
      Write **ADR-0012** with `/adr` — *"Challenge retention and recurring background work"*
      — carrying the argument from the technical plan rather than re-deriving it, including:
      **(1)** the predicate is `expires_at + retention` and the two tables' notions of "dead" are
      deliberately never consulted; **(2)** deletion is set-based behind a port method, with the rule
      that generalises it — *an aggregate governs state transitions, not its own non-existence; put
      in the Domain the part that can be wrong* — and why that does **not** contradict ADR-0011
      decision 4's rejection of a bulk `UPDATE`; **(3)** the two retention windows and the fact that
      their ordering is inverted relative to the lifetimes; **(4)** no new aggregate, no event, no
      value object, and why each absence is a decision; **(5)** recurring work runs as a console
      command under host cron, with the **Constitution §3** reading stated explicitly; **(6)** the
      orphan answer — incidental collection by expiry, a read-only probe, and the erasure ordering
      rule (**challenge rows first, `identity_user` last**) plus the carve-out that retention windows
      do not apply to an erasure request; **(7)** the background-job observability contract (a log
      line every run including zeros, a heartbeat, and a backlog that cannot be faked).
      Plus, in the same commit: a **dated amendment to ADR-0009** (decision 4's orphan clause is
      discharged — *answered*, not reversed: no FK is added), a **dated amendment to ADR-0011** (its
      *"the pruning debt now spans two tables"* consequence is discharged), and the **roadmap** line
      116–120 updated.
      **No code in this commit.**
- [ ] **T1 (checkpoint, not a change):** confirm this slice needs **no new Composer package, no new
      env var, no new Compose service and no new Messenger transport**. Record it in the commit
      message. *Stated as a task because ADR-0010's amendment makes a new required boot-path env var
      a four-place change — `app`, `messenger-worker`, CI, image build — and the way that bites is by
      being added without anyone noticing it was added.* **(AC-35)**

## Domain

- [ ] **T2:** `Entity/EmailVerificationRequest` — `RETENTION_AFTER_EXPIRY_SECONDS = 604800` and
      `retentionThreshold()`. **One constant, one static, nothing else in the diff.** The docblock
      must say why 7 days: the durable fact lives on `identity_user.email_verified_at`, so the row
      only has to outlive the *support* question; and this is the higher-volume table. It must name
      `PasswordResetRequest` and state that the longer window there is deliberate.
      `sub()` with an explicit `\DateInterval` — **not** `modify()`, **not** `strtotime`.
      **(AC-1, AC-2, AC-30)**
- [ ] **T3:** `Entity/PasswordResetRequest` — `RETENTION_AFTER_EXPIRY_SECONDS = 2592000` and
      `retentionThreshold()`. Same shape, same constraints. The docblock must say why 30 days: the
      row survives to answer the **incident-review** question, while
      `identity_user.password_changed_at` answers the coarse one forever — and it must state, in
      these words, that the risk of keeping it too long is a **data-protection** cost and not a
      credential one, because the row holds only the digest of a dead secret. **(AC-1, AC-2, AC-30)**
- [ ] **T4:** `Port/EmailVerificationRequestRepository` and `Port/PasswordResetRequestRepository` —
      `countExpiredBefore()` and `deleteExpiredBefore()` on **each**, declared separately, named for
      their own aggregate. Both docblocks must carry: the threshold is passed in, never computed
      here; the comparison is a **strict `<`** and that is contract, not implementation; the
      predicate is `expires_at` **and nothing else**, with a pointer to the reason (a "dead"
      predicate would be the rejected `Challenge` base class expressed in SQL); the return value is
      the number **actually deleted**; `$limit` is mandatory with no default; neither method hydrates
      an aggregate. Each also discharges that port's existing *"the pruning job will add its own
      method, with its own justification"* note — **update that note rather than leaving it
      pointing at the future.** **(AC-4, AC-7, AC-17, AC-32)**
- [ ] **T5:** Checkpoint — `make stan deptrac`; confirm zero framework imports under `Domain/`;
      confirm the **two aggregate diffs are exactly** one constant and one static each, with no new
      import; confirm no file was added under
      `Domain/Identity/{Entity,ValueObject,Event,Exception}/`. **(AC-30, AC-31, AC-33)**

## Application

- [ ] **T6:** `ChallengePruningReport` + `SweepOutcome` — `final readonly`, directly under
      `Application/Identity/` following `VerificationOutcome`'s precedent. Fields exactly as the
      technical plan lists them. The docblock says why a report exists here when slice 3's commands
      returned `void`: the caller cannot reconstruct these numbers afterwards without describing a
      different moment.
- [ ] **T7:** `Command/PruneExpiredChallenges` (`?int $limit`, `bool $dryRun`) +
      `Handler/PruneExpiredChallengesHandler` — the three **Application** constants
      (`BATCH_SIZE = 1000`, `MAX_BATCHES_PER_TABLE = 50`, `MAX_RUN_SECONDS = 30`) with a comment
      saying why they are *not* Domain constants; **one `now()` pins both thresholds**; the backlog
      counted **before** each sweep; the batch loop terminating on a short batch; both caps setting
      `truncated`.
      **The two uses of `Clock` must carry a comment distinguishing them** — step 1's instant is
      *pinned* and defines "overdue" for this run; the loop's calls are a *stopwatch*. Conflating
      them lets the threshold drift mid-run, and a `FrozenClock` hides it.
      **(AC-10, AC-11, AC-12, AC-18, AC-19, AC-34)**

## Infrastructure — persistence

- [ ] **T8:** `DoctrineEmailVerificationRequestRepository` + `DoctrinePasswordResetRequestRepository`
      — `countExpiredBefore()` (DQL `COUNT`, `(int)` cast) and `deleteExpiredBefore()` (native SQL
      `DELETE … WHERE id IN (SELECT id … ORDER BY expires_at LIMIT :limit)` via
      `Connection::executeStatement()`).
      **`:threshold` bound with `Types::DATETIMETZ_IMMUTABLE` explicitly on every call** — the naive
      inference is the single most likely bug in this slice, and it is invisible on a UTC box.
      The docblock must say why the `DELETE` is native SQL (DQL has no `LIMIT` and no subquery in
      `WHERE IN`), why `ORDER BY expires_at` is load-bearing (monotone progress for a truncated run;
      the index walks the left edge), and why there is **no `FOR UPDATE SKIP LOCKED`** (the property
      it buys is already held by idempotency). **(AC-4, AC-11, AC-17)**
- [ ] **T9:** `Persistence/Doctrine/ChallengeIntegrityProbe` — DBAL `NOT EXISTS` anti-join per table,
      **read-only**, implementing **no** Domain port. The docblock must argue the boundary crossing:
      *"does this row's user still exist?"* is a storage-integrity question, not a domain one; no use
      case asks it; modelling it as a port would put a cross-aggregate JOIN into the Domain's
      vocabulary. It must state that the class never deletes, and it must not acquire a delete method
      later. **(AC-25)**
- [ ] **T10:** Migration — two `CREATE INDEX` statements on `expires_at`, plus the matching
      `<index/>` elements in **both** XML mappings in the same commit (so the mapping and the schema
      never disagree and `make migration.make` stays quiet forever). Generated with
      `make migration.make`, then **hand-reviewed**: no column change, no FK, no touched index, a
      `down()` that drops exactly the two. Record in the docblock that this discharges slice 3's
      *"the pruning job will want one; it can add it, with a caller to justify it"* — and that the
      justification is the **batching**, not the table size. `make migrate` + `make test.db`, then
      `migrate prev` and `migrate` **by hand**. **(AC-28, AC-36)**

## Infrastructure — console, probe wiring, health

- [ ] **T11:** `config/packages/monolog.yaml` — add the `pruning` channel. One line, its own commit,
      because everything downstream logs to it.
- [ ] **T12:** `Console/PruneChallengesCommand` (`muzbar:identity:prune-challenges`) — `--dry-run`,
      `--limit` (validated at the boundary: non-numeric or non-positive → exit 1 **before** the
      handler runs), the Redis `SET NX EX` run lock, the handler call, the probe, the **single** INFO
      log line with the full field set, the heartbeat write, the `SymfonyStyle` table, and the exit
      codes.
      **The lock's acquisition site must carry a comment saying the lock is politeness, not
      correctness** — correctness comes from idempotency — so nobody later "hardens" it and makes
      Redis a hard requirement of housekeeping. Redis errors on the lock or the heartbeat log at
      **warning** and do **not** change the exit code. **(AC-16, AC-18, AC-19, AC-20, AC-21, AC-38)**
- [ ] **T13:** `HealthController::ready()` — add the **reporting-only** `jobs.challenge_pruning`
      body section (`last_run`, `age_seconds`, `overdue_verification`, `overdue_reset`, `stale`;
      `stale` when age > 10800 s or the key is absent). **The status code must not change on any of
      it**, and the code must carry a comment saying why: readiness answers "should traffic come
      here", and 503-ing over a housekeeping job would have Docker restart a healthy container in a
      loop. **(AC-22, AC-23, AC-24)**

## Tests (qa — written after implementation, by the independent agent)

- [ ] **T14:** Test support — a `ClearsPruningState` trait for the two new Redis keys (or extend
      `ClearsRateLimiters` **and rename it**, because a trait named for rate limiters that also
      clears job state is exactly the drift CLAUDE.md warns about). Add the `expiredLongAgo()` state
      to both existing Foundry factories, **through `issue()` via `instantiateWith()`** as they
      already do. **Run `make test` twice in a row before ticking this.** **(AC-42)**
- [ ] **T15:** Domain unit — `retentionThreshold()` on both aggregates: exact instants, UTC and
      whole-second preservation; both constants asserted against the **literal from the feature
      spec**, never against the constant itself; and **AC-3's inversion assertion** with a failure
      message naming the reason. *This is the one test in the slice whose job is to make a future
      refactor fail.* Plus the arithmetic form of I-26: a request built through `issue()` is never
      before its own retention threshold. **(AC-1, AC-2, AC-3)**
- [ ] **T16:** Integration — both adapters' new methods: **both sides of the boundary**
      (`threshold − 1 s` deleted; `threshold` and `threshold + 1 s` kept); a live row never deleted;
      a redeemed row inside the window never deleted; an **invalidated but unexpired** reset row
      never deleted; `$limit` honoured with the return value asserted against the **observed row
      delta**, not a literal. **(AC-4, AC-5, AC-6, AC-7, AC-19)**
- [ ] **T17:** Integration — the two rows that can only be made with raw SQL, each with a docblock
      saying why it is written outside the model: a **corrupt `token_hash`** is still deleted
      (proving nothing hydrates), and a row whose `expires_at − issued_at` disagrees with the current
      `LIFETIME_SECONDS` is judged by its **stored** value. **(AC-9, AC-17)**
- [ ] **T18:** Integration — `ChallengeIntegrityProbe`: a hand-inserted orphan is counted **and is
      still present afterwards**; an **overdue** orphan is removed by the ordinary sweep with no
      special handling. **(AC-25, AC-26)**
- [ ] **T19:** Integration — `PruneExpiredChallengesHandler` with `FrozenClock`: one `now()` pins
      both thresholds; the loop terminates on a short batch; `MAX_BATCHES_PER_TABLE` yields
      `truncated: true` and a **second invocation drains the remainder** with nothing deleted twice
      and nothing skipped; `dryRun` reports the same counts and deletes nothing; an empty database
      returns an all-zero report.
      **The docblock must state that `MAX_RUN_SECONDS` is *not* covered** — a frozen clock never
      reaches the deadline — rather than implying both caps are tested. *A docblock claiming coverage
      the assertion cannot deliver is the same defect one level up.* **(AC-10, AC-13, AC-14, AC-18)**
- [ ] **T20:** Functional — the command via `CommandTester`: exit 0 on a clean run; exit 0 and
      `skipped: lock_held` with the lock key pre-set; the **single** log line with every field
      present, captured with `RecordingLogger`; the heartbeat written on a real run and **not** on
      `--dry-run`; `--limit=0` rejected before the handler runs. **(AC-16, AC-20, AC-21, AC-38)**
- [ ] **T21:** Functional — `/health/ready`: the new section's shape; **200 with an absent
      heartbeat, 200 with an ancient one, and 200 with a large backlog**; `stale` asserted on **both
      sides** of the 3-hour boundary. **(AC-22, AC-23)**
- [ ] **T22:** Functional — **AC-24's distinguishability test**, as *one* readable test with two
      phases: nothing overdue → `overdue_* === 0`; insert overdue rows and do **not** sweep →
      `overdue_* > 0`. This assertion is the slice's entire observability claim; splitting it into
      two tests lets the halves drift. **(AC-24)**
- [ ] **T23:** Functional — **AC-6 end-to-end**: issue a reset, redeem it, run a sweep, replay the
      link, and assert **both** that the response is the invalid-link one **and** that the log
      recorded `PasswordResetLinkAlreadyUsed` rather than `PasswordResetRequestNotFound`. Asserting
      only the HTTP response would pass either way, which would make the test a comment. **(AC-6)**
- [ ] **T24:** Infrastructure assertions — `EXPLAIN` shows an Index Scan on each new index for the
      count **and** for the delete's selection subquery, on both tables; the SQL logger over one full
      run shows **no write** to `identity_user` and no read but the probe; `symfony/scheduler` is not
      an **installed package** (check `composer.lock`'s `packages` list — **not** a grep, which
      already matches `framework-bundle`'s `conflict` block on a clean checkout); no `Challenge` type
      exists; no SQL/DQL string appears under `Application/` or `Domain/`.
      **(AC-8, AC-32, AC-34, AC-35, AC-37)**

## Docs & verify

- [ ] **T25:** Ops docs — `docs/infrastructure.md`: the **runbook entry** (the exact cron line
      `17 * * * * cd /home/muzbar-deploy/muzbar.com && docker compose exec -T app php bin/console
      muzbar:identity:prune-challenges`, the container it targets, the first-run procedure, and
      **what to check when the backlog grows**); the two retention windows and the note that they and
      the **database-dump schedule must be decided together**, since a dump is the only backstop
      behind a window that turns out too short; and the **GDPR-erasure specification** — challenge
      rows in **both** tables deleted **before** the `identity_user` row, and retention windows do
      **not** apply to an erasure request. **(AC-29, AC-39)**
- [ ] **T26:** Docs: `CLAUDE.md` (the first `DELETE` in the codebase and the rule that licences it —
      *put in the Domain the part that can be wrong*; the two retention windows and their deliberate
      inversion; the new `pruning` channel; the **two new Redis keys DAMA does not roll back**, added
      to the existing rate-limiter warning), `docs/roadmap.md` (tick the slice; add the
      `messenger_messages` pruning item and the `deploy.yml`-never-restarts-the-worker item to
      `devops`), `FORboehpyk.md` (the story — *the two tables disagree about "dead", so the sweep
      stops asking*; why an aggregate has no opinion about its own non-existence; and the finding
      that the deploy pipeline has been leaving the worker on a stale image).
- [ ] **T27:** `/verify` → `make check` green, reviewer PASS (zero CRITICAL / MAJOR), all 42
      acceptance criteria checked off, then open the PR.

      **Five things `make check` cannot tell you, and this slice's verification must not take on
      trust:**
      1. **Read the two `deleteExpiredBefore()` implementations by hand** (technical plan, Risk 9).
         This is the first `DELETE` this repository has ever shipped, and "we have never deleted
         before" is exactly the condition under which a bad delete goes unnoticed.
      2. **Rehearse the first run**: `--dry-run`, then a small explicit `--limit`, then enable cron —
         and paste both outputs into the verification notes (**AC-40**). The first real run is the
         only one that can meet an unbounded backlog.
      3. **Run `make test` twice in a row** (**AC-42**). Two new Redis keys survive DAMA.
      4. **`docker compose ps` shows `messenger-worker` Up, not Restarting** — ADR-0010's amendment
         makes this part of verifying anything that touches the boot path, and T11's Monolog change
         does.
      5. **Confirm the two retention docblocks each name the twin and give the reason for the
         difference.** A future reader will find two identically shaped constants with different
         values and, absent reasons, will eventually "fix" one.
</content>
