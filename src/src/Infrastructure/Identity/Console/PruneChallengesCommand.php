<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Console;

use App\Application\Identity\ChallengePruningReport;
use App\Application\Identity\Command\PruneExpiredChallenges;
use App\Application\Identity\Handler\PruneExpiredChallengesHandler;
use App\Application\Identity\SweepOutcome;
use App\Domain\Shared\Port\Clock;
use App\Infrastructure\Identity\Persistence\Doctrine\ChallengeIntegrityProbe;
use Predis\ClientInterface as RedisClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only caller of `PruneExpiredChallengesHandler`, and the thing host cron runs every hour.
 *
 * WHY A CONSOLE COMMAND AND NOT `symfony/scheduler`. Argued in full in ADR-0012 decision 5; the
 * short version is that Scheduler is a transport, a transport needs a consumer, a consumer is a
 * second daemon — and `deploy.yml` today runs `pull app` and `up -d app nginx`, so it never restarts
 * the daemon this deployment *already* has. A scheduler container added on those terms would run
 * whatever image it last had, which for a mail worker costs a stale template and for a pruner would
 * mean **deleting data according to a retention policy nobody currently believes is in force.** Cron
 * running `docker compose exec -T app …` targets the one container the deploy does update. The cost
 * is honest and stated: the trigger lives in a crontab outside git, which is why AC-39 puts the
 * exact line in `docs/infrastructure.md` and AC-24 makes its absence detectable from *inside* the
 * application, by a backlog that grows when nothing runs.
 *
 * WHY THE LOCK, THE HEARTBEAT AND THE PROBE LIVE HERE RATHER THAN IN THE HANDLER. All three are
 * Redis or raw SQL — Infrastructure — and the Application layer may not reach it. That is the rule;
 * the payoff is bigger than the rule. `PruneExpiredChallengesHandler` stays a pure function of its
 * three ports, so the entire use case, both caps and the batching loop included, is exercisable with
 * two fakes and a frozen clock, with no Redis, no probe and no console anywhere near the test.
 * Everything that had to touch a real piece of infrastructure was pushed out to exactly one place,
 * and this file is that place.
 *
 * WHAT THIS COMMAND OWES THE OPERATOR, IN ORDER OF HOW MUCH IT IS WORTH. The backlog first — the
 * two `overdueBefore` counts the handler measured *before* deleting anything, which is the only
 * number that cannot be faked by a job that runs and reports success while doing nothing. Then the
 * heartbeat, which distinguishes "has not run" from "ran and had nothing to do" *early*, before a
 * backlog has had time to build. Then the single INFO line, which is the record that a run happened
 * at all. AC-20 requires that line on **every** run, including the all-zeros one, because the line
 * is the evidence and the deletion count is merely one of its fields.
 *
 * @see PruneExpiredChallengesHandler
 */
#[AsCommand(
    name: 'muzbar:identity:prune-challenges',
    description: 'Deletes challenge rows that have outlived their retention window, in bounded batches.',
)]
final class PruneChallengesCommand extends Command
{
    /**
     * The run lock. Named as a constant so `/health/ready`, this command and every test refer to one
     * spelling of it rather than to four string literals that can drift apart while all four stay
     * green.
     *
     * DAMA rolls back Postgres and not Redis, so this key and the heartbeat below **survive between
     * tests** exactly like the rate-limiter keys already documented in CLAUDE.md. A test that leaves
     * the lock set poisons every later test in the file, and the symptom — a run that reports
     * `skipped: lock_held` and exits 0 — looks like a pass from a distance.
     */
    public const string LOCK_KEY = 'identity:challenge_pruning:lock';

    /**
     * The heartbeat: an ISO-8601 instant, no TTL, overwritten by every completed non-dry run.
     *
     * **No TTL on purpose.** A key that expired on its own would make "the job has not run for four
     * hours" and "the job has never run" the same observation, and would quietly turn Redis's
     * eviction policy into part of the health check's semantics. The age is computed from the stored
     * instant, so an old heartbeat is *evidence* rather than an absence.
     */
    public const string HEARTBEAT_KEY = 'identity:challenge_pruning:last_run';

    /**
     * How long the run lock survives if the process holding it dies without releasing it.
     *
     * One hour, matching the cron interval: a crashed run must not be able to lock the next
     * scheduled one out of the table for longer than a single slot. It is a safety net rather than a
     * budget — the run's own budget is `PruneExpiredChallengesHandler::MAX_RUN_SECONDS`, thirty
     * seconds, two orders of magnitude shorter.
     */
    public const int LOCK_TTL_SECONDS = 3600;

    public function __construct(
        private readonly PruneExpiredChallengesHandler $pruneExpiredChallenges,
        private readonly ChallengeIntegrityProbe $integrityProbe,
        private readonly RedisClient $redis,
        private readonly Clock $clock,
        // Autowired by parameter name: MonologBundle publishes
        // `Psr\Log\LoggerInterface $pruningLogger` → `monolog.logger.pruning` for every declared
        // channel, so the `pruning` channel added to `config/packages/monolog.yaml` is all the
        // wiring this needs. The concrete service id is the one a test must replace — Symfony's
        // `ResolveReferencesToAliasesPass` rewrites the alias to its target at *compile* time, so
        // by the time a test runs, this constructor is already wired to `monolog.logger.pruning`
        // and setting the alias would change nothing while failing silently (CLAUDE.md).
        private readonly LoggerInterface $pruningLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Measure and report every count, delete nothing, and write no heartbeat',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Cap the number of rows deleted from each table this run (positive integer)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // THE STOPWATCH STARTS HERE, AND IT IS DELIBERATELY NOT THE `Clock`. `hrtime(true)` is a
        // monotonic nanosecond counter, not a wall-clock reading. Two properties matter. First,
        // resolution: the `Clock` port mandates **whole seconds** and `SystemClock` truncates
        // microseconds at the source (ADR-0007 — `datetimetz_immutable` discards them at the type,
        // so an instant carrying them would mean one thing in memory and another after a reload).
        // A stopwatch built on it could therefore only ever report 0 ms or 1000 ms, which is not a
        // duration, it is a rounding artefact wearing a field name. Second, monotonicity: an NTP
        // step in the middle of a run would move a wall clock and could produce a negative
        // `duration_ms`; `hrtime()` cannot go backwards.
        //
        // This does **not** demote the `Clock`. It stays the single source of "now" for everything
        // the Domain sees — both retention thresholds, the lock value and the heartbeat all come
        // from it — precisely so that time remains an injected value a test can freeze. What is
        // being said here is narrower: *an elapsed interval is not an instant*, and the port that
        // supplies instants is the wrong instrument for measuring one. `duration_ms` never reaches
        // the Domain; it reaches a log line.
        $startedAtNanos = hrtime(true);

        // VALIDATION HAPPENS AT THE BOUNDARY, BEFORE THE HANDLER RUNS. Constitution §8: untrusted
        // input crosses a validation boundary before it reaches the Domain — and **a CLI argument
        // is untrusted input even when the only person who can supply it is the operator.** The
        // point is not distrust of the operator; it is that `--limit=0` and `--limit=abc` must fail
        // as *input errors*, loudly and before anything is deleted, rather than as whatever the
        // batching loop happens to do with a zero. `PruneExpiredChallenges` accepts `?int $limit`
        // and documents that a non-positive value is refused here, which is only true if it is.
        $rawLimit = $input->getOption('limit');
        $limit = null;

        if (null !== $rawLimit) {
            // `ctype_digit()` rather than `is_numeric()`: the latter accepts `-1`, `1.5`, `0x1A`
            // and `1e3`, every one of which would then be silently reshaped by an `(int)` cast into
            // something the operator did not type. A limit is a count of rows, so the only honest
            // spelling of one is a run of digits.
            if (!\is_string($rawLimit) || !ctype_digit($rawLimit) || 0 === (int) $rawLimit) {
                $io->error(\sprintf(
                    '--limit must be a positive integer, got "%s". Nothing was read and nothing was deleted.',
                    \is_scalar($rawLimit) ? (string) $rawLimit : get_debug_type($rawLimit),
                ));

                return Command::FAILURE;
            }

            $limit = (int) $rawLimit;
        }

        $dryRun = true === $input->getOption('dry-run');

        // The command's own `Clock` reading, used for the lock value and the heartbeat and for
        // **nothing else**. It is emphatically not a threshold: the handler takes its own single
        // pinned reading and derives both retention thresholds from that, so which rows are overdue
        // is decided in one place by one instant. If this reading and the handler's ever straddle a
        // second boundary the only consequence is a heartbeat one second early, which is the
        // conservative direction.
        $startedAt = $this->clock->now();
        $lockValue = \sprintf('pid=%d started=%s', (int) getmypid(), $startedAt->format(\DateTimeInterface::ATOM));

        $lock = $this->acquireRunLock($lockValue);

        if (false === $lock) {
            // A skipped run emits its own single INFO line and deliberately **not** AC-20's full
            // field set. There are no numbers to report — nothing was counted, no threshold was
            // derived — and reporting zeros would put a line in the log that is byte-comparable
            // with a clean sweep of an empty table. The whole point of the line is to distinguish
            // states; a line that cannot is worse than no line.
            $this->pruningLogger->info('identity.challenge_pruning.skipped', ['skipped' => 'lock_held']);
            $io->warning('Another pruning run holds the lock; this run did nothing. Exiting 0.');

            // Exit 0: a skipped run is not a failed run. Cron would otherwise mail the operator
            // about an hourly job doing exactly what the lock exists to make it do.
            return Command::SUCCESS;
        }

        try {
            $report = ($this->pruneExpiredChallenges)(new PruneExpiredChallenges($limit, $dryRun))
                // The probe runs **after** the sweep, so its counts describe orphans still present
                // rather than orphans that existed a moment ago — a number an operator can act on.
                // The handler cannot run it: it is Infrastructure with raw SQL and no port, because
                // *"does this row's user still exist?"* is a storage-integrity question and not a
                // domain one (ADR-0012 decision 6).
                ->withOrphanCounts(
                    $this->integrityProbe->countOrphanedEmailVerificationRequests(),
                    $this->integrityProbe->countOrphanedPasswordResetRequests(),
                );

            $this->warnOnOrphans($report);
            $this->logRun($report, $this->elapsedMilliseconds($startedAtNanos));

            if (!$dryRun) {
                // AC-21. A dry run writes no heartbeat, because a rehearsal that made the system
                // look freshly swept would be lying about the one signal that says the job is
                // alive.
                $this->writeHeartbeat($startedAt);
            }
        } catch (\Throwable $e) {
            // A GENUINE FAILURE — Postgres unreachable, or anything else nobody predicted. Exit 1,
            // logged at **error** (AC-38), so cron carries a non-zero status and the error handler
            // stack has something to react to. This run emits no AC-20 line, which is correct: that
            // line reports what a run measured, and this run measured nothing. Batches already
            // committed stay committed and the next run continues from wherever this one stopped —
            // there is no partial state to clean up, because a row is either deleted or it is not.
            $this->pruningLogger->error('identity.challenge_pruning.failed', [
                'exception' => $e,
                'dry_run' => $dryRun,
            ]);
            $io->error(\sprintf('Pruning failed: %s', $e->getMessage()));

            return Command::FAILURE;
        } finally {
            // Released here, after the heartbeat and before anything is rendered, and in a
            // `finally` so that a failure path cannot leave the next scheduled run waiting out an
            // hour of TTL for a process that is already gone. Only if this run actually took it:
            // when Redis was unreachable (`null`) there is nothing to release, and deleting a key
            // this run never acquired is how a fail-open lock quietly becomes a lock that clears
            // someone else's.
            if (true === $lock) {
                $this->releaseRunLock();
            }
        }

        $this->render($io, $report);

        // Exit 0 on a truncated run too (AC-38). Truncation is not failure: batches commit
        // independently, nothing is lost, nothing repeats, and the next hourly run continues from
        // wherever this one stopped. A non-zero exit here would train the operator to ignore cron
        // mail from the one job whose failure mode is silence.
        return Command::SUCCESS;
    }

    /**
     * `SET <key> <value> NX EX 3600` — the run lock.
     *
     * **THIS LOCK IS POLITENESS, NOT CORRECTNESS, AND THAT MUST STAY TRUE.** Correctness comes from
     * idempotency: a `DELETE` matching a predicate deletes nothing the second time it runs, there is
     * no marker to check, no state to reconcile and nothing to compensate. Two overlapping runs
     * would produce *correct* results and waste transactions; skipping is simply the better use of a
     * single VDS. That is the entire justification for the fail-open branch below, and it is written
     * here rather than in a document because the change that would break it is a small, well-meaning
     * one: someone "hardens" the lock — retries it, refuses to proceed without it, adds a Lua
     * compare-and-swap release — and housekeeping silently acquires a hard runtime dependency on a
     * cache, for a property it already has for free. If Redis is down, the rows still need deleting.
     *
     * `pg_try_advisory_lock` was the considered alternative and is a defensible one — same
     * connection, no fail-open ambiguity, no Redis. It was declined because it would put a
     * Postgres-specific concurrency primitive into a job for a property that is explicitly *not* a
     * correctness requirement. If that trade is ever revisited, the change is contained to this
     * method.
     *
     * @return bool|null `true` — acquired; this run owns the key and must release it.
     *                   `false` — another run holds it; this run must skip and exit 0 (AC-16).
     *                   `null` — Redis could not be reached. The lock is simply unavailable, the run
     *                   **proceeds** without one, and the fact is logged at warning. Three outcomes
     *                   rather than two because "nobody may run" and "nobody could ask" are
     *                   different facts, and collapsing them would make a Redis outage stop
     *                   housekeeping — the exact coupling the paragraph above refuses
     */
    private function acquireRunLock(string $value): ?bool
    {
        try {
            // Predis returns a `Status` on success and `null` when `NX` declined to overwrite an
            // existing key. There is no exception on the "held" path, which is why it cannot be
            // conflated with the error path below.
            return null !== $this->redis->set(self::LOCK_KEY, $value, 'EX', self::LOCK_TTL_SECONDS, 'NX');
        } catch (\Throwable $e) {
            $this->pruningLogger->warning('identity.challenge_pruning.lock_unavailable', [
                'exception' => $e,
                'key' => self::LOCK_KEY,
            ]);

            return null;
        }
    }

    /**
     * Drops the lock so the next scheduled run does not have to wait out the TTL.
     *
     * A plain `DEL` rather than a compare-and-delete against the stored value. The strictly correct
     * form checks ownership atomically in Lua, because a run that overran its TTL could otherwise
     * delete a *successor's* lock. That is a real race and it is knowingly accepted: the worst it
     * can produce is two overlapping runs, which idempotency already makes harmless, and buying
     * protection from it would mean shipping a Lua script to defend a mechanism whose docblock says
     * in capitals that it is not a correctness mechanism. Cheap protection against a harmless
     * outcome is still a step towards the lock being load-bearing.
     *
     * A Redis failure here is swallowed to a warning: the work is already done, the TTL will clear
     * the key within the hour, and failing the command over an unreleased lock would report a
     * successful sweep as a failure.
     */
    private function releaseRunLock(): void
    {
        try {
            $this->redis->del([self::LOCK_KEY]);
        } catch (\Throwable $e) {
            $this->pruningLogger->warning('identity.challenge_pruning.lock_release_failed', [
                'exception' => $e,
                'key' => self::LOCK_KEY,
            ]);
        }
    }

    /**
     * AC-21: the `Clock`'s instant, ISO-8601, no TTL.
     *
     * A failure here logs at **warning** and does not touch the exit code, because the work was
     * done: rows were deleted and committed, and reporting that as a failed run would send cron mail
     * about a successful sweep and, worse, would train the operator to ignore it. The visible cost
     * of a missing heartbeat is that `/health/ready` reports the pruner stale — which is precisely
     * why the **backlog**, not the heartbeat, is the primary signal (AC-24). The secondary signal
     * degrading is exactly what a secondary signal is allowed to do.
     */
    private function writeHeartbeat(\DateTimeImmutable $at): void
    {
        try {
            $this->redis->set(self::HEARTBEAT_KEY, $at->format(\DateTimeInterface::ATOM));
        } catch (\Throwable $e) {
            $this->pruningLogger->warning('identity.challenge_pruning.heartbeat_failed', [
                'exception' => $e,
                'key' => self::HEARTBEAT_KEY,
            ]);
        }
    }

    /**
     * AC-27: a non-zero orphan count is additionally logged at **warning**.
     *
     * "Additionally" is the operative word — the two counts are in the run's INFO line either way,
     * so this is not the record that they exist; it is the record that they are not zero. Nothing in
     * the application deletes a `User`, so the honest expectation is a permanent zero, and today a
     * non-zero reading can mean only manual database surgery or a bug.
     *
     * **Warning rather than error, and the distinction is a decision.** The rows are not wrong.
     * They are collected by the ordinary sweep on the ordinary schedule, on `expires_at` like
     * everything else, with no special handling and no reference to their orphan status (AC-26).
     * Orphan-ness is a state that resolves itself inside the retention window: it is a thing to
     * *report*, never a thing to select on. An error would imply something must be done right now,
     * and the correct response is to find out what created them — which is what "the same non-zero
     * reading persisting across runs" tells you and a single reading does not.
     */
    private function warnOnOrphans(ChallengePruningReport $report): void
    {
        if (0 === $report->orphanedVerification && 0 === $report->orphanedReset) {
            return;
        }

        $this->pruningLogger->warning('identity.challenge_pruning.orphans_present', [
            'orphaned_verification' => $report->orphanedVerification,
            'orphaned_reset' => $report->orphanedReset,
        ]);
    }

    /**
     * AC-20's line: **exactly one** INFO record, on every run, with the full field set.
     *
     * Including the all-zeros run — that is the requirement, and it is the whole design rather than
     * completeness for its own sake. A job that logs only when it deletes something is
     * indistinguishable, in a log, from a job that has stopped running. **The line is the record
     * that a run happened; the deletion count is one of its fields.**
     *
     * HOW THE TWO TABLES ARE COMBINED, SINCE THE REPORT KEEPS THEM SEPARATE AND THE LINE MUST NOT.
     * `batches` is the **sum** of the two `SweepOutcome::$batches`, and `truncated` is their
     * **logical OR**. Both are chosen for the same reason: the field answers a question about the
     * *run*, and the honest answer to "did work remain when this run stopped?" is yes if it remained
     * anywhere. A per-table split would have been defensible too — and is available without it,
     * because `overdue_*` and `deleted_*` are already per-table, so a reader who needs to know
     * *which* table truncated can subtract. Summing a count and OR-ing a flag loses nothing the
     * other twelve fields do not already carry.
     *
     * The two thresholds are formatted as ISO-8601 rather than shipped as objects: a log line is
     * read by grep and by whatever ingests JSON next, and `2026-07-31T09:17:00+00:00` is
     * unambiguous in both — including about the offset, which is the field a naive `Y-m-d H:i:s`
     * would drop and which is exactly the bug this slice's adapters bind `DATETIMETZ_IMMUTABLE` to
     * avoid.
     */
    private function logRun(ChallengePruningReport $report, int $durationMs): void
    {
        $this->pruningLogger->info('identity.challenge_pruning.completed', [
            'threshold_verification' => $report->verification->thresholdAt->format(\DateTimeInterface::ATOM),
            'threshold_reset' => $report->reset->thresholdAt->format(\DateTimeInterface::ATOM),
            'overdue_verification' => $report->verification->overdueBefore,
            'overdue_reset' => $report->reset->overdueBefore,
            'deleted_verification' => $report->verification->deleted,
            'deleted_reset' => $report->reset->deleted,
            'orphaned_verification' => $report->orphanedVerification,
            'orphaned_reset' => $report->orphanedReset,
            'batches' => $report->verification->batches + $report->reset->batches,
            'truncated' => $report->verification->truncated || $report->reset->truncated,
            'dry_run' => $report->dryRun,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Elapsed wall time in whole milliseconds, from the monotonic counter read at the top of
     * `execute()`.
     *
     * `hrtime(true)` returns nanoseconds as an `int`; the division is what makes the unit in the
     * field name true, and `(int) round(...)` rather than an integer division so a 1.6 ms run does
     * not report as 1 ms. See the comment at the call site for why the `Clock` port is not the
     * instrument for this.
     */
    private function elapsedMilliseconds(int $startedAtNanos): int
    {
        return (int) round((hrtime(true) - $startedAtNanos) / 1_000_000);
    }

    /**
     * The human's copy of the same facts.
     *
     * Deliberately a second rendering rather than a pretty-printed version of the log context: the
     * log line is for grep and for whatever ingests it later, and a table is for the operator
     * standing at a terminal during AC-40's rehearsed first run. They report the same numbers and
     * neither is derived from the other's formatting, which is why both name the report's fields
     * directly.
     */
    private function render(SymfonyStyle $io, ChallengePruningReport $report): void
    {
        $io->table(
            ['Table', 'Threshold', 'Overdue before', 'Deleted', 'Batches', 'Truncated'],
            [
                $this->row('identity_email_verification_request', $report->verification),
                $this->row('identity_password_reset_request', $report->reset),
            ],
        );

        $io->definitionList(
            ['Orphaned verification rows' => (string) $report->orphanedVerification],
            ['Orphaned reset rows' => (string) $report->orphanedReset],
            ['Dry run' => $report->dryRun ? 'yes — nothing was deleted and no heartbeat was written' : 'no'],
        );

        if ($report->verification->truncated || $report->reset->truncated) {
            // Said out loud rather than left in a table cell, because "work remains" is the one
            // outcome an operator might otherwise read as "done". It is still exit 0: the next
            // hourly run continues from here.
            //
            // THE WORDING BRANCHES BECAUSE THE FLAG MEANS TWO DIFFERENT THINGS, which is
            // `SweepOutcome::$truncated`'s own docblock rather than a subtlety invented here: on a
            // real run it means a cap stopped the loop before the table drained; on a dry run it
            // means there is a backlog and — by design — nothing was done about it. One sentence
            // covering both would have to be vague enough to be true of either, and an operator
            // rehearsing the first run (AC-40) is precisely the reader who must not be told a cap
            // fired when no loop ever started.
            $io->note($report->dryRun
                ? 'There is a backlog and a dry run deletes none of it. A real run would start on it.'
                : 'A cap stopped this run before the table was drained. The next run continues from here.');
        }

        $io->success($report->dryRun ? 'Dry run complete; nothing was deleted.' : 'Pruning run complete.');
    }

    /**
     * @return array{string, string, int, int, int, string}
     */
    private function row(string $table, SweepOutcome $outcome): array
    {
        return [
            $table,
            $outcome->thresholdAt->format(\DateTimeInterface::ATOM),
            $outcome->overdueBefore,
            $outcome->deleted,
            $outcome->batches,
            $outcome->truncated ? 'yes' : 'no',
        ];
    }
}
