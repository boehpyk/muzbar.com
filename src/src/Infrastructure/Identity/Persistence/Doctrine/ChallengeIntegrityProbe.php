<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * Counts challenge rows whose `user_id` matches no `identity_user` row — one anti-join per challenge
 * table, read-only, reported by the pruning run and by `/health/ready`.
 *
 * WHY THIS CLASS IMPLEMENTS NO DOMAIN PORT, WHICH IS THE ONLY INTERESTING THING ABOUT IT.
 *
 * *"Does this row's user still exist?"* is **not a domain question.** No use case asks it. No
 * aggregate can answer it — `PasswordResetRequest` holds a `UserId` and has no way to look one up,
 * which is the entire point of referencing another root by identity. And no business rule depends on
 * the answer: an orphaned challenge is not invalid, not refused, not treated differently by any
 * handler, and its user's absence changes nothing about whether its token redeems. What the question
 * is actually about is **storage integrity** — precisely the thing ADR-0009 decision 4 declined to
 * delegate to a foreign key and declared to be "the application's job". This class is that job,
 * finally being done, in the layer that owns the storage.
 *
 * Giving it a port would cost the thing the design is protecting. A `Domain\Identity\Port\...`
 * interface with a method meaning *"count rows referencing a missing user"* would put a
 * cross-aggregate `JOIN` into the Domain's own vocabulary, and once it is in the vocabulary it is
 * precedent: the next reader who wants "reset requests whose user is unverified" has a sanctioned
 * place to put it, and the boundary that made `UserId`-not-`User` meaningful erodes one convenience
 * query at a time. That is the door this design is closing, not opening — so the anti-join lives
 * here, where it can be read as SQL about tables rather than as a sentence about aggregates.
 *
 * THE COUNTER-ARGUMENT, STATED HONESTLY RATHER THAN OMITTED. An Infrastructure class holding raw SQL
 * behind no interface is untestable through a seam and unswappable by configuration: nothing can fake
 * it, nothing can decorate it, and a test must have a database. Accepted, on two grounds. It is
 * exercised against the real `muzbar_test` like every other adapter in this context (AC-25), which is
 * the standard this repository already holds Infrastructure to. And there is nothing to swap it for —
 * a second implementation of "count orphans in Postgres" is not a thing anyone will ever write.
 *
 * **THIS CLASS NEVER DELETES.** It counts. Nothing here removes a row, nothing here writes a row, and
 * the two statements below are `SELECT COUNT(*)` in their entirety. Said again because it is the
 * property most worth protecting: **it must not acquire a delete method later.** A probe that can
 * delete is one well-meaning refactor away from being a second retention policy — undocumented,
 * un-ADR'd, running on a different trigger from the first, and deleting on a criterion (*orphan-ness*)
 * that ADR-0012 explicitly declined. If a future slice needs to remove a person's rows, that is GDPR
 * erasure: it belongs behind `deleteForUser()` on the two ports, with the ordering rule (challenge
 * rows first, `identity_user` last) and the carve-out that retention windows do not apply to it. Not
 * here.
 *
 * ORPHAN-NESS IS NOT A PRUNE CRITERION, AND THE TWO IDEAS MUST NOT MERGE. An orphan past its retention
 * window is deleted by the ordinary sweep, with no special handling and no reference to its orphan
 * status (AC-26) — it qualifies on `expires_at` like everything else. Orphan-ness is a state that
 * resolves itself inside the window; it is a thing to *report*, never a thing to select on.
 *
 * WHEN IT RUNS AND WHAT A READING MEANS. The command calls it **after** the sweep, so it reports
 * orphans *currently present* rather than orphans that existed a moment ago — a number an operator can
 * act on, not a historical curiosity. A single non-zero reading is a fact; the same reading persisting
 * across runs means something is **actively creating** orphans, because the sweep would otherwise have
 * carried the old ones off within the window. Today it can mean only two things: manual database
 * surgery, or a bug. **Nothing in the application deletes a `User`**, so the honest expectation is a
 * permanent zero, and the day it is not zero is a day worth a warning log line (AC-27).
 *
 * Cost: two anti-joins per run, against tables the sweep exists to keep small.
 */
final readonly class ChallengeIntegrityProbe
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Email-verification requests referencing a user that is not there.
     *
     * `NOT EXISTS` RATHER THAN `NOT IN`, AND THE REASON SURVIVES THE FACT THAT IT DOES NOT MATTER
     * TODAY. `NOT IN (SELECT ...)` has famously surprising semantics against a nullable inner column:
     * a single `NULL` in the subquery makes the whole predicate `UNKNOWN` and the outer query returns
     * **nothing**, silently, with no error and no warning — an orphan count of zero that means "there
     * is a NULL somewhere" rather than "all is well". `identity_user.id` is `NOT NULL`, so the
     * semantics are moot here; the habit is not, and neither is the plan, since `NOT EXISTS` gives
     * Postgres an anti-join it can hash while `NOT IN` frequently degrades into a per-row subplan.
     *
     * `COUNT(*)` rather than `COUNT(r.id)`: there is no nullable column to discriminate on and the
     * question is purely "how many rows", so the form that says exactly that is the honest one.
     *
     * The scalar is cast for the reason every count in this context is cast — PostgreSQL returns
     * `bigint`, PDO surfaces `bigint` as a string, and an uncast return would make the `int` signature
     * a claim PHP quietly rewrites on the way out. DBAL's `fetchOne()` is typed `mixed`, so the
     * annotation below is a claim rather than an inference — the same honesty
     * `DoctrinePasswordResetRequestRepository::findOutstandingForUser()` applies to its `getResult()`:
     * PHPStan at `max` cannot see inside the SQL, and `SELECT COUNT(*)` with no `GROUP BY` is what
     * makes exactly one non-null numeric scalar true.
     */
    public function countOrphanedEmailVerificationRequests(): int
    {
        /** @var int|numeric-string $count */
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                  FROM identity_email_verification_request r
                 WHERE NOT EXISTS (
                     SELECT 1
                       FROM identity_user u
                      WHERE u.id = r.user_id
                 )
                SQL
        );

        return (int) $count;
    }

    /**
     * Password-reset requests referencing a user that is not there.
     *
     * The same anti-join against the other table, and deliberately a **second method** rather than one
     * method taking a table name. A parameter that selects a table is the shape decision 2 of the
     * technical plan rejected for the sweeper port — SQL wearing an abstraction's hat — and it would
     * be no better one layer down: it would make this the only place in `Identity` where a table is
     * addressed by string, and it would put a caller-supplied identifier next to a `FROM` clause.
     * Two five-line methods cost less than that sentence did.
     *
     * Everything `countOrphanedEmailVerificationRequests()` says about `NOT EXISTS` versus `NOT IN`,
     * about `COUNT(*)`, and about the cast applies here unchanged and is not repeated. **What is worth
     * repeating is that this method, too, only counts.** These rows are personal data — a dated record
     * that a named person asked to recover their account — and an orphan is not a licence to delete
     * one early. It waits for its retention window like every other row.
     */
    public function countOrphanedPasswordResetRequests(): int
    {
        /** @var int|numeric-string $count */
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                  FROM identity_password_reset_request r
                 WHERE NOT EXISTS (
                     SELECT 1
                       FROM identity_user u
                      WHERE u.id = r.user_id
                 )
                SQL
        );

        return (int) $count;
    }
}
