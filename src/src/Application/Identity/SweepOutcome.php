<?php

declare(strict_types=1);

namespace App\Application\Identity;

/**
 * What one run did to **one** challenge table.
 *
 * There are two of these per run and they are never merged, which is the same rule the rest of this
 * slice keeps: `EmailVerificationRequest` and `PasswordResetRequest` have independently chosen
 * retention windows, so their thresholds differ, their backlogs differ, and one of them can be
 * truncated while the other drains completely. A single flattened set of counters would have to pick
 * one `thresholdAt` to report and would then be describing a moment that only half the run happened
 * at. Two outcomes side by side is the report saying what actually took place.
 *
 * NOTE WHAT THIS TYPE IS NOT. It is not a `Challenge` abstraction sneaking in through the back door
 * (AC-32). It names no aggregate, holds no policy, and has no method either aggregate could
 * disagree with — it is five numbers a caller reads. The thing the slice refuses to share is the
 * *policy*: which rows qualify, and for how long they are kept. That stayed on the two aggregates,
 * as two constants and two statics that no caller can override. Sharing a result shape while
 * refusing to share a decision is exactly the split the technical plan names: *the reuse is at the
 * level of ports and infrastructure; the duplication is at the level of policy objects.*
 *
 * Every field is **measured**, never assumed. `deleted` is the sum of what the port reported
 * actually deleting rather than `batches × BATCH_SIZE`, and `overdueBefore` is a count taken before
 * a single row was removed. A report built out of what the code intended to do is a report that
 * cannot disagree with the code, which makes it worthless for the one job it has.
 */
final readonly class SweepOutcome
{
    /**
     * @param \DateTimeImmutable $thresholdAt   the instant this table's rows were judged against —
     *                                          `now − RETENTION_AFTER_EXPIRY_SECONDS` for *this*
     *                                          aggregate. Reported rather than recomputed by the
     *                                          caller, so the log line names the boundary the sweep
     *                                          actually used
     * @param int                $overdueBefore rows whose `expiresAt` was strictly before
     *                                          `$thresholdAt`, counted **before** the sweep. This is
     *                                          the backlog (AC-24): in a healthy system it is ~0
     *                                          after every run, and if the job stops it grows
     *                                          monotonically. Measured afterwards it would always be
     *                                          ~0 and would prove nothing
     * @param int                $deleted       rows actually removed, summed from the port's return
     *                                          values. Always `0` on a dry run
     * @param int                $batches       how many `deleteExpiredBefore()` calls were made.
     *                                          `0` on a dry run
     * @param bool               $truncated     `true` when a cap stopped the loop rather than the
     *                                          table running dry — so work may remain — or, on a dry
     *                                          run, when there was a backlog and nothing was done
     *                                          about it. It is deliberately the conservative
     *                                          reading: an explicit `--limit` that happens to drain
     *                                          the table exactly reports `true`, because proving
     *                                          otherwise would cost a second count of a number the
     *                                          next run reports for free. **A truncated run is not a
     *                                          failed run**: batches commit independently, nothing
     *                                          is lost and nothing repeats, and the next run
     *                                          continues from wherever this one stopped (AC-12,
     *                                          AC-14)
     */
    public function __construct(
        public \DateTimeImmutable $thresholdAt,
        public int $overdueBefore,
        public int $deleted,
        public int $batches,
        public bool $truncated,
    ) {
    }
}
