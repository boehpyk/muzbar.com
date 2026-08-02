<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "delete the challenge rows that have outlived the questions they can still answer".
 *
 * Two primitives, per slice 1's rule: a command is data an adapter can build without knowing the
 * Domain's types. There is nothing here worth promoting to a value object either — see the technical
 * plan's negative case. A `RetentionWindow` would be a wrapper around a compile-time constant with
 * no input to validate; a `BatchSize` would be a wrapper around a number the *handler* owns and this
 * command never carries. What is conspicuously absent is more interesting than what is present:
 * **no threshold and no retention window.** They are derived inside the aggregates' own statics from
 * a single `Clock` reading, so no caller — not this command, not a console option, not a future
 * admin screen — can supply one. That is invariant I-15's rule one level up: *a window a caller may
 * supply is a default, not a rule, and the first caller in a hurry quietly becomes the second
 * policy.* On a flow whose job is to delete personal data, that property is worth the rigidity.
 *
 * WHY `dryRun` IS A FIELD RATHER THAN A SECOND HANDLER. "Tell me what this would do" is not a
 * different use case; it is the same one, stopping one step short. It asks the same question of the
 * same two tables at the same instant, derives the same two thresholds from the same aggregates'
 * constants, and reports the same backlog — the *only* difference is whether the batching loop runs.
 * A `DryRunPruneExpiredChallengesHandler` would therefore have to duplicate the threshold
 * derivation, which is precisely the part that must not drift: the whole value of a rehearsal
 * (AC-40) is that it exercises the arithmetic the real run will use, and two copies of that
 * arithmetic is exactly one copy too many. The moment they disagreed, the dry run would be a
 * reassuring report about a sweep that never happens.
 *
 * The inverse framing is worth stating too, because it is the reason this is not simply laziness: a
 * flag on a command is the wrong answer whenever the two branches would have *different rules* —
 * different validation, different invariants, different failure contracts. Here they have none of
 * that. Same policy, same counts, same exit code, same report shape, with `deleted` necessarily 0.
 *
 * @see \App\Application\Identity\Handler\PruneExpiredChallengesHandler
 */
final readonly class PruneExpiredChallenges
{
    /**
     * @param int|null $limit  the maximum number of rows to delete **per table** this run. `null`
     *                         means "use the handler's configured per-run cap"
     *                         (`MAX_BATCHES_PER_TABLE × BATCH_SIZE`), which is the ordinary
     *                         scheduled case; a value is supplied only by an operator rehearsing the
     *                         first run or deliberately taking a small bite out of a large backlog
     *                         (AC-19, AC-40). A non-positive value is refused at the console
     *                         boundary before the handler is ever invoked — a CLI argument is
     *                         untrusted input even when only the operator can type it
     * @param bool     $dryRun when `true`, every count is still measured and reported and **nothing
     *                         is deleted** (AC-18). The command additionally declines to write the
     *                         heartbeat on a dry run, because a rehearsal that made the system look
     *                         freshly swept would be lying about the one signal that says the job is
     *                         alive
     */
    public function __construct(
        public ?int $limit,
        public bool $dryRun,
    ) {
    }
}
