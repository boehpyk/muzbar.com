<?php

declare(strict_types=1);

namespace App\Application\Identity;

/**
 * Everything one run of `PruneExpiredChallengesHandler` observed and did, for the adapter that
 * invoked it.
 *
 * WHY A RESULT TYPE EXISTS HERE WHEN SLICE 3'S COMMANDS RETURN `void`. `RequestPasswordResetHandler`
 * and `ResetPasswordWithTokenHandler` hand nothing back because their callers need nothing back: a
 * controller redirects, and any fact it wants is either already in its hands or re-readable at
 * leisure without changing its meaning. This caller is different in a way that is about *time*
 * rather than about convenience. The console command has to write one log line and one table
 * carrying the backlog, the deletions, the batch counts and the two thresholds — and **every one of
 * those numbers describes a moment that no longer exists by the time the handler returns.**
 * Re-querying to rebuild them would not produce a slightly stale report; it would produce an
 * accurate report *of a different run*, in which the backlog is ~0 precisely because the sweep just
 * cleared it. The number an operator most needs is the one a second query destroys.
 *
 * `VerificationOutcome` established the bar for an Application-layer result type — legitimate when
 * the caller genuinely cannot reconstruct the answer, illegitimate when it is a convenience that
 * adds a concept the model does not have. This clears the same bar with more fields, and for the
 * same reason it is not in `Domain`: no domain expert speaks of a "pruning report". They speak of
 * how long a recovery challenge is kept, and that sentence lives on the aggregates as
 * `RETENTION_AFTER_EXPIRY_SECONDS`, where it belongs.
 *
 * WHY THE TWO ORPHAN COUNTS ARRIVE SEPARATELY, VIA `withOrphanCounts()`. They are read by
 * `ChallengeIntegrityProbe`, which is an **Infrastructure** class with raw SQL and no port — quite
 * deliberately, because *"does this row's user still exist?"* is a storage-integrity question and
 * not a domain one (ADR-0012 decision 6). The Application layer may not reach Infrastructure, so the
 * handler cannot run the probe, must not know it exists, and stays a pure function of its ports —
 * which is what makes the whole use case testable with two fakes and a frozen clock, with no Redis
 * and no probe in sight. The console command runs the probe **after** the sweep, so that the counts
 * describe orphans still present, and folds them in here.
 *
 * That is why the constructor takes all five arguments with **no default on the orphan counts**, and
 * why the wither is named for what it does. A defaulted `int $orphanedVerification = 0` would make
 * "nobody has probed yet" and "probed, and there are none" the identical value reached two different
 * ways, and would let a future caller forget the probe entirely without a single line of code
 * looking wrong. `withOrphanCounts()` cannot be forgotten quietly: the handler passes explicit zeros
 * under a comment saying they are placeholders, and the one call site that supplies real ones names
 * itself. The alternative shape — assembling the report in the command out of the handler's two
 * `SweepOutcome`s — was rejected for the opposite failure: it would let the *adapter* decide what a
 * run's result looks like, and a second adapter would then be free to assemble a different one.
 *
 * `final readonly`, so a report is a statement about a run rather than a mutable scratchpad; the
 * wither returns a new instance for the same reason.
 */
final readonly class ChallengePruningReport
{
    /**
     * @param SweepOutcome $verification         what happened to `identity_email_verification_request`
     * @param SweepOutcome $reset                what happened to `identity_password_reset_request`
     * @param int          $orphanedVerification verification rows whose `user_id` matches no
     *                                           `identity_user`, counted after the sweep and
     *                                           **never** deleted early — orphan-ness is not a
     *                                           prune criterion (AC-25, AC-26)
     * @param int          $orphanedReset        the same reading for the reset table
     * @param bool         $dryRun               whether this run was allowed to delete anything. Carried
     *                                           in the report rather than left to the caller to
     *                                           remember, because a log line showing `deleted: 0`
     *                                           means two very different things depending on it
     */
    public function __construct(
        public SweepOutcome $verification,
        public SweepOutcome $reset,
        public int $orphanedVerification,
        public int $orphanedReset,
        public bool $dryRun,
    ) {
    }

    /**
     * The same run, with the orphan readings the handler could not take itself.
     *
     * Called once, by `PruneChallengesCommand`, immediately after it runs `ChallengeIntegrityProbe`.
     * Everything else is copied unchanged: this method exists to attach two numbers from a different
     * collaborator, not to revise anything the sweep reported.
     */
    public function withOrphanCounts(int $orphanedVerification, int $orphanedReset): self
    {
        return new self(
            $this->verification,
            $this->reset,
            $orphanedVerification,
            $orphanedReset,
            $this->dryRun,
        );
    }
}
