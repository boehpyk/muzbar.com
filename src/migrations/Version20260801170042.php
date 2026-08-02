<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two indexes on `expires_at`, one per challenge table, and **nothing else** — the whole schema cost
 * of `identity-challenge-pruning`.
 *
 * THIS DISCHARGES A NOTE SLICE 3 LEFT ON PURPOSE. `Version20260729153542`'s docblock declined an index
 * here and said why, in these words: *"There is no index on `expires_at`. Expiry is never a query
 * predicate — the aggregate judges it in PHP against an instant from the `Clock` port, and the
 * repository deliberately refuses to know what time it is. The pruning job will want one; it can add
 * it, with a caller to justify it."* This is that caller. The refusal is not reversed — the repository
 * still does not know what time it is, and the threshold still arrives from the `Clock` port through
 * the handler; what changed is that `expires_at` is now a query predicate, which it was not then.
 *
 * **THE JUSTIFICATION IS THE BATCHING, NOT THE TABLE SIZE.** "The tables might get big" would be a
 * weak argument today and an honest reader would notice: both tables hold a few rows per registration
 * and the sweep exists precisely to keep them small. The real argument is that the sweep multiplies
 * the cost of a scan by the number of batches. A run is up to fifty bounded `DELETE`s per table, each
 * selecting the oldest thousand overdue rows; without an index that is **fifty sequential scans of
 * the whole table per run, every hour**, and each one re-reads the rows the previous batch already
 * deleted past. With the index it is fifty range scans down the left edge of a B-tree, each touching
 * only the rows it is about to remove — which is also why the adapters' subqueries carry
 * `ORDER BY expires_at` rather than trusting the planner to pick a direction.
 *
 * Three absences, each a decision rather than an omission:
 *
 * - **Not `CREATE INDEX CONCURRENTLY`.** Both tables are small today, so the `SHARE` lock a plain
 *   `CREATE INDEX` takes is held for milliseconds and blocks writes for that long — a cost not worth
 *   paying complexity to avoid. Worth writing down for the day it *is* worth it: `CONCURRENTLY`
 *   cannot run inside a transaction, and Doctrine wraps each migration in one, so a migration using
 *   it must declare `isTransactional(): false` — and then owns the consequence, since a failed
 *   concurrent build leaves an `INVALID` index sitting in the catalogue that must be dropped by hand.
 *   A real footgun, disarmed here only by the tables being small.
 * - **No foreign key is added** (AC-28). ADR-0009 decision 4 stands, unamended: referential integrity
 *   between two aggregate roots is the application's job, and a hand-added FK the mapping knows
 *   nothing about would be diffed as unwanted by every later `make migration.make`. This slice
 *   *discharges* that decision's orphan clause rather than reversing it — `ChallengeIntegrityProbe`
 *   counts orphans and reports them, and an overdue orphan is carried off by the ordinary sweep with
 *   no special handling.
 * - **No backfill and no data migration.** Every existing row already has the `expires_at` the sweep
 *   reads — `issue()` has derived it since slice 2 — so there is nothing to compute, nothing to
 *   rewrite and no window in which old and new rows are judged differently.
 *
 * Both indexes are also declared in the two `.orm.xml` mappings, in this same commit, and that
 * pairing is the point: an index the database has and the mapping does not is permanent diff noise,
 * offered for deletion by every subsequent `make migration.make`. That is ADR-0009 decision 4's
 * argument against a hand-added constraint, run in the positive direction.
 *
 * **Deploy-order note, because it is a real window rather than a theoretical one.** The pipeline
 * brings the new image up and runs migrations *after* it. Between those two moments the
 * `muzbar:identity:prune-challenges` command exists and these indexes do not. A sweep that lands in
 * that gap is still **correct** — the predicate is a comparison against a stored column and depends
 * on no index — merely slow, and the first run is a rehearsed manual one anyway (AC-40).
 */
final class Version20260801170042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: index expires_at on both challenge tables for the pruning sweep. Two indexes, no column change, no foreign key, no backfill.';
    }

    public function up(Schema $schema): void
    {
        // The higher-volume of the two tables, and therefore the one that needs this most: a
        // verification request is written on every registration and every resend, the per-hour cap is
        // five rather than three, and the lifetime is twenty-four times longer — so it accumulates
        // faster in every dimension, and its retention window is the shorter of the two to match.
        //
        // A plain B-tree on a single column, which is the whole shape the sweep asks for. Not a
        // composite: nothing queries `expires_at` alongside anything else. Not a partial index on
        // some notion of "dead": that predicate is exactly the shared abstraction ADR-0012 decision 1
        // rejected, and putting it in an index definition would hide it somewhere no unit test could
        // ever read it — worse than the SQL string the decision was about, because an index predicate
        // is invisible from PHP entirely.
        //
        // Serves both halves of the sweep, which is why one index is enough for two statements:
        // `countExpiredBefore()`'s range count and the selection subquery inside
        // `deleteExpiredBefore()`, whose `ORDER BY expires_at LIMIT n` this index answers by walking
        // its own leading edge with no sort step at all. AC-37 pins both plans with `EXPLAIN`.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_identity_email_verification_request_expires_at
                ON identity_email_verification_request (expires_at)
            SQL);

        // The same index on the other table, and deliberately a separate statement over a separate
        // name rather than anything shared. The two tables agree about `expires_at` and about
        // nothing else — they disagree about what a finished row is, by design, on four inverted
        // rules — so the fact that these two lines are near-identical is a fact about a column, not
        // evidence that the sweep is one thing wearing two names. The retention *windows* they serve
        // differ by a factor of four.
        //
        // Named explicitly, as every index in this context is, because the mapping, the adapters'
        // docblocks and AC-37's `EXPLAIN` assertions all refer to it by name — and an assertion
        // cannot refer to a name nobody chose.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_identity_password_reset_request_expires_at
                ON identity_password_reset_request (expires_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Exactly the two indexes this migration created, and nothing near them. No table is dropped,
        // no column is touched, and neither `idx_*_user_issued` nor either `uniq_*_token_hash` is
        // mentioned — a `down()` that reached one object further would take the anti-abuse counts and
        // the token lookups down with it.
        //
        // Run by hand rather than assumed, as slice 3's was: `doctrine:migrations:migrate prev` then
        // `make migrate`, because a `down()` nobody has ever executed is a comment. This slice ships
        // exactly **one** migration, so `prev` genuinely reverses it in a single step.
        //
        // Rolling back leaves the sweep working and slow, not broken — which is the same property the
        // deploy-order note in the class docblock relies on.
        $this->addSql('DROP INDEX idx_identity_email_verification_request_expires_at');
        $this->addSql('DROP INDEX idx_identity_password_reset_request_expires_at');
    }
}
