<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\ChallengePruningReport;
use App\Application\Identity\Command\PruneExpiredChallenges;
use App\Application\Identity\SweepOutcome;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Shared\Port\Clock;

/**
 * Sweeps both challenge tables of rows that have outlived their retention window, in bounded
 * batches, and reports what it saw.
 *
 * A handler is deliberately boring and this one is the most boring yet: read down `__invoke()` and
 * every *decision* is somewhere else. **Which** rows qualify is on the two aggregates — one constant
 * and one pure static each, independently chosen, unshared and un-overridable. **How** a row is
 * removed is behind a port and lives in a Doctrine adapter as one set-based statement. What is left
 * here is the third thing, and naming it as separate from the other two is the whole lesson of the
 * slice: **how much work one invocation should do.** That is neither a business statement nor a
 * storage mechanic. A domain expert has no opinion about 1000; an operator has a very firm one.
 *
 * NOTHING HERE KNOWS WHAT "DEAD" MEANS, AND THAT IS THE DESIGN. `EmailVerificationRequest` absorbs
 * replays and has one terminal column; `PasswordResetRequest` refuses them, invalidates its siblings
 * on reissue, has two terminal columns and a staleness rule that lives on a *different* aggregate.
 * Those disagreements are deliberate (ADR-0011 decision 9), and a sweep predicate built out of
 * "dead" would be the rejected shared base class re-derived one layer down, in SQL, where no unit
 * test can reach it. So the sweep never asks. It asks about `expiresAt`, the one column both tables
 * define identically, both aggregates derive identically inside `issue()`, and which is a *ceiling*
 * on every other reason a row could be finished — a redeemed row expires, an invalidated row
 * expires, a stale row expires. ADR-0012 decision 1 has the argument in full.
 *
 * THIS CLASS IS A PURE FUNCTION OF ITS PORTS, AND THAT IS LOAD-BEARING RATHER THAN TIDY. The run
 * lock, the heartbeat and the orphan probe are all Redis or raw SQL, all Infrastructure, and all
 * live in `PruneChallengesCommand`. The Application layer may not reach Infrastructure — but the
 * gain is bigger than compliance: the entire use case, including both caps and the batching, is
 * exercisable with two fake repositories and a frozen clock, with no Redis, no probe and no console.
 *
 * `ChallengePruningReport` carries the argument for why a result type is legitimate here when slice
 * 3's handlers return `void`.
 */
final readonly class PruneExpiredChallengesHandler
{
    /**
     * How many rows one `deleteExpiredBefore()` call may remove.
     *
     * THESE THREE ARE **APPLICATION** CONSTANTS AND NOT DOMAIN ONES (AC-34), and the line is drawn
     * on purpose rather than by where they happened to be needed. `RETENTION_AFTER_EXPIRY_SECONDS`
     * is a business statement — *"we keep a recovery challenge for a month"* — that a domain expert
     * would say out loud, that can be **wrong**, and that a pure unit test can pin. These three are
     * a statement about a machine: how long a housekeeping job may hold a connection and how big a
     * bite it may take out of a table. Nobody in the business has a view on 1000, changing it cannot
     * make the system incorrect, and putting it beside the retention window would quietly suggest
     * the two are the same kind of thing. AC-34's grep enforces the split from both sides: the
     * retention numbers appear only under `Domain/`, these only under `Application/`.
     *
     * 1000 rather than "all of them": a bounded statement is one a `EXPLAIN` can be reasoned about,
     * one whose lock footprint is knowable, and one whose interruption costs at most one batch.
     */
    public const int BATCH_SIZE = 1000;

    /**
     * How many batches one run may take **per table** — 50, so at most 50 000 rows each.
     *
     * The second cap exists because the first one cannot be tested. `MAX_RUN_SECONDS` is measured
     * with the `Clock`, and a `FrozenClock` — the only kind a test may use here — never reaches a
     * deadline, so the wall-clock cap is structurally invisible to every test that could assert it.
     * This cap is clock-independent, so a test *can* drive a run into it, watch `truncated` come
     * back `true`, and watch a second invocation drain the remainder (AC-14). A cap nothing can
     * exercise is a cap nobody should rely on alone.
     */
    public const int MAX_BATCHES_PER_TABLE = 50;

    /**
     * The wall-clock budget for one run, in seconds.
     *
     * Hourly cron plus a 30-second budget means a pathological run can never overlap the next one by
     * more than politeness allows, and a run that meets an enormous backlog stops being a surprise
     * long before it becomes an incident. Hitting it sets `truncated` and exits 0; the next run
     * continues from wherever this one stopped.
     */
    public const int MAX_RUN_SECONDS = 30;

    public function __construct(
        private EmailVerificationRequestRepository $verificationRequests,
        private PasswordResetRequestRepository $resetRequests,
        private Clock $clock,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: **one transaction per batch, and that is the design rather than a leak.**
     * Each `deleteExpiredBefore()` call is a single statement the adapter executes and commits. The
     * tidy-looking alternative — one transaction wrapping the whole run — would hold locks and grow
     * WAL for the run's entire duration, and would make a truncated run un-resumable, because
     * hitting a cap would roll back every batch that had already succeeded and the next run would
     * start from exactly where this one did. Nothing spans the two tables either: there is no
     * invariant between them, which is the reason two separate aggregates were legitimate in the
     * first place.
     *
     * IDEMPOTENT BY CONSTRUCTION, WHICH IS WHY THE RUN LOCK IS POLITENESS RATHER THAN CORRECTNESS.
     * A `DELETE` matching a predicate deletes nothing the second time it runs (AC-13) — there is no
     * marker to check, no state to reconcile and nothing to compensate. A run killed mid-sweep
     * leaves whole batches committed and **no partial row state**, because there is none to have: a
     * row is either deleted or it is not (AC-15). Two overlapping runs would produce correct results
     * and waste transactions. That is the entire reason `PruneChallengesCommand`'s Redis lock is
     * allowed to fail open, and it must stay true — if anyone ever "hardens" that lock, housekeeping
     * acquires a hard dependency on a cache for a property it already has for free.
     *
     * **This method declares no `@throws`, and unlike the neighbouring handlers that is not a
     * carefully argued omission — there is simply nothing to declare.** Slice 3's handlers throw
     * because an unknown address or a spent link are ordinary business outcomes the domain has an
     * answer for. A sweep has no business rule it can violate: a truncated run is an operational
     * outcome carried in the report, and Postgres being unreachable is an infrastructure failure
     * that propagates untouched for `PruneChallengesCommand` to turn into exit 1 and an error log
     * (AC-38). Catching it here to return a "failed" report would let a caller ignore the failure
     * and carry on, which is the same mistake `VerificationOutcome` avoided by having no `Failed`
     * case.
     */
    public function __invoke(PruneExpiredChallenges $command): ChallengePruningReport
    {
        // CLOCK USE #1 OF 2 — THE **PINNED** INSTANT. This single reading defines what "overdue"
        // means for this entire run. Both thresholds below are derived from it, so the two sweeps
        // and the two backlog counts describe one moment rather than four; and it must not move
        // while the run proceeds, or rows that crossed their threshold *during* the run would be
        // swept or spared depending on how long the first table took — non-determinism nobody could
        // reproduce. Two `now()` calls would agree almost always (the `Clock` contract mandates
        // whole seconds, so they differ only across a second boundary) and that "almost" is the
        // whole problem: **a frozen clock returns the same value however many times it is asked, so
        // no test can see the discrepancy.** A value a test cannot distinguish from correct is one
        // that has to be made correct by construction. Contrast clock use #2, in `sweep()`.
        $now = $this->clock->now();

        // Two thresholds, derived separately by each aggregate from its own constant, because the
        // two retention windows are independently chosen policy — 7 days and 30 days, deliberately
        // inverted relative to the lifetimes (AC-3). Neither window is a parameter and neither can
        // be supplied from here: I-15's rule one level up. This is also why decision 2 declined a
        // generic sweeper port taking the window as an argument — it would have made the policy a
        // default, and the first caller in a hurry the second policy.
        $verificationThreshold = EmailVerificationRequest::retentionThreshold($now);
        $resetThreshold = PasswordResetRequest::retentionThreshold($now);

        // The stopwatch's zero. Derived from the pinned instant rather than from a fresh reading,
        // because the run started at `$now` and a second call here would only add a needless
        // opportunity for the two to disagree.
        $runDeadline = $now->add(new \DateInterval(\sprintf('PT%dS', self::MAX_RUN_SECONDS)));

        return new ChallengePruningReport(
            $this->sweep($this->verificationRequests, $verificationThreshold, $command, $runDeadline),
            $this->sweep($this->resetRequests, $resetThreshold, $command, $runDeadline),
            // The orphan counts are placeholders, not readings. `ChallengeIntegrityProbe` is an
            // Infrastructure class with raw SQL and no port — a deliberate decision, because *"does
            // this row's user still exist?"* is a storage-integrity question rather than a domain
            // one — so this handler cannot run it and must not know it exists. The console command
            // runs it after the sweep and folds the real numbers in with `withOrphanCounts()`.
            0,
            0,
            $command->dryRun,
        );
    }

    /**
     * One table, from the backlog count through the batching loop.
     *
     * THE ONE PIECE OF THIS SLICE THAT IS DELIBERATELY SHARED BETWEEN THE TWO AGGREGATES, AND THE
     * DISTINCTION IS THE POINT RATHER THAN AN EXCEPTION TO IT. The technical plan draws the line in
     * so many words: *what is shared is the arithmetic, the strict `<`, the batching loop, the
     * report type and the log line — all of it in the Application and Infrastructure layers, where
     * reuse belongs; the duplication is at the level of policy objects.* Every rule the two
     * aggregates disagree about stayed upstairs: two retention constants, two thresholds derived by
     * two statics, two ports, two `SweepOutcome`s. What arrives here is a repository and an already
     * computed instant, and this method has no way to influence either.
     *
     * The union type is why that stays true, and it is not a `Challenge` abstraction wearing a
     * disguise (AC-32). It introduces no type, names both aggregates' repositories explicitly, is
     * `private` so nothing outside this class can reach it, and dispatches on nothing. A third
     * challenge aggregate could not silently inherit this policy: it would have to be *added to the
     * signature by hand*, which is exactly the property decision 2 wanted from declaring the port
     * methods separately — the generalisation cannot spread quietly. A shared *port* would have had
     * to take the retention window as a parameter, and that is the thing that was refused.
     *
     * @param \DateTimeImmutable $runDeadline shared across both tables on purpose: the budget is for
     *                                        the run, not per table, so a first sweep that eats it
     *                                        all leaves the second reporting `truncated` honestly
     *                                        rather than quietly starting a second 30 seconds
     */
    private function sweep(
        EmailVerificationRequestRepository|PasswordResetRequestRepository $repository,
        \DateTimeImmutable $threshold,
        PruneExpiredChallenges $command,
        \DateTimeImmutable $runDeadline,
    ): SweepOutcome {
        // THE BACKLOG IS COUNTED **BEFORE** ANYTHING IS DELETED, AND THE ORDER IS THE WHOLE
        // OBSERVABILITY DESIGN RATHER THAN A CONVENIENCE. A count taken afterwards is always ~0 in
        // a system where the job works, and *also* ~0 in a system where the job runs and does
        // nothing, so it distinguishes precisely the two states this slice exists to tell apart.
        // Counted first, it is the number that grows monotonically when the job stops, cannot be
        // faked by a run that reports success and deletes nothing, and survives Redis being flushed
        // — which is why it, and not the heartbeat, is the signal to trust (AC-24).
        $overdueBefore = $repository->countExpiredBefore($threshold);

        if ($command->dryRun) {
            // A rehearsal reports everything and touches nothing (AC-18). `truncated` is `true`
            // whenever there is a backlog, because the honest reading of the flag is "work remains
            // after this run" — and after a dry run, all of it does.
            return new SweepOutcome($threshold, $overdueBefore, 0, 0, $overdueBefore > 0);
        }

        $deleted = 0;
        $batches = 0;
        $truncated = false;

        while (true) {
            $batchSize = self::BATCH_SIZE;

            if (null !== $command->limit) {
                // `--limit=N` caps the **total rows deleted from this table** this run (AC-19), not
                // the batch size — so it is applied by shrinking the last batch rather than by
                // replacing `BATCH_SIZE`, and the port is never asked for more than the cap allows.
                // It only ever tightens: `MAX_BATCHES_PER_TABLE` and `MAX_RUN_SECONDS` still bind
                // (AC-12), because an operator typing a large number should not be able to talk the
                // job into an unbounded run — which is the one thing every cap here exists to
                // prevent.
                $remaining = $command->limit - $deleted;

                if ($remaining <= 0) {
                    $truncated = true;

                    break;
                }

                $batchSize = min(self::BATCH_SIZE, $remaining);
            }

            $removed = $repository->deleteExpiredBefore($threshold, $batchSize);
            $deleted += $removed;
            ++$batches;

            // TERMINATION ON A **SHORT** BATCH RATHER THAN AN EMPTY ONE. Fewer rows came back than
            // were asked for, so there were no more to find and the table is drained. It reads like
            // an off-by-one, which is why it is commented: the `=== 0` form a reader expects would
            // be correct but would pay for one extra round trip at the end of *every* sweep, on
            // every table, forever, to learn something this comparison already told us.
            if ($removed < $batchSize) {
                break;
            }

            if ($batches >= self::MAX_BATCHES_PER_TABLE) {
                $truncated = true;

                break;
            }

            // CLOCK USE #2 OF 2 — THE **STOPWATCH**, and it is a different thing from the pinned
            // instant in `__invoke()` even though it comes from the same port. That one defines
            // which rows are overdue and must never move; this one only asks whether the run has
            // spent its budget, and is *expected* to move. Conflating them — re-deriving a
            // threshold from a reading taken here — is the subtle bug this slice is set up to walk
            // into, and a `FrozenClock` hides it completely: the deadline is never reached, so the
            // loop looks identical whichever instant fed the threshold. `MAX_BATCHES_PER_TABLE`
            // exists as a second, clock-independent cap precisely so that a test has something it
            // can actually drive.
            if ($this->clock->now() >= $runDeadline) {
                $truncated = true;

                break;
            }
        }

        return new SweepOutcome($threshold, $overdueBefore, $deleted, $batches, $truncated);
    }
}
