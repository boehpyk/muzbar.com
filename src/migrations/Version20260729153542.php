<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `Identity` context's third table — one row per issued password-reset challenge — plus the
 * first change to `identity_user` since slice 1.
 *
 * Additive on both halves, in the expand/contract sense: it creates a table that exists nowhere, and
 * it adds one **nullable** column to an existing table with no default and no backfill. Old rows
 * stay valid, old code keeps running against the new schema, and the deploy needs no coordination.
 *
 * WHY `identity_user` IS TOUCHED AT ALL, WHEN SLICE 2 PROUDLY DID NOT. Slice 1 shipped
 * `email_verified_at` up front precisely so that `identity-email-verification` would need no schema
 * change against the user table, and that foresight is not available twice. `password_changed_at` is
 * not speculative state bolted on for a future feature: it is what makes invariant I-23 expressible
 * — *a reset request issued strictly before its user's last password change is not redeemable* —
 * and I-23 is the second layer behind the invalidation sweep. If that sweep is ever lost to a crash
 * or a concurrent request, a sibling reset request stays live and redeemable; one column and one
 * comparison close that, with no cross-row write and no dependence on the sweep having succeeded.
 * Without it the slice would ship a replayable account-takeover primitive guarded only by a write
 * that is allowed to fail.
 *
 * **There is deliberately no backfill of `password_changed_at`.** `NULL` is not "unknown" here, it
 * is the true state: *this account's password has never been changed since registration*. Writing
 * `registered_at` into it would assert a password change that never happened and would make I-23
 * start refusing legitimate links issued in the same second as a registration.
 *
 * Two absences are decisions rather than omissions, and both would otherwise be "fixed" by a
 * well-meaning future reader:
 *
 * - **There is no foreign key on `user_id`** (ADR-0009 decision 4, AC-40). Aggregates reference each
 *   other by identity, so referential integrity between two roots is the application's job, not the
 *   schema's. A hand-added FK would also be permanent noise: Doctrine's mapping does not know about
 *   it, so every subsequent `make migration.make` would diff it as an unwanted constraint and offer
 *   to drop it — deleted and re-added forever by whoever ran the generator last. The generator
 *   agreed here without being argued with, which is the check that matters: **an FK appearing in
 *   this diff would have meant the mapping grew an association**, and the fix would be the mapping,
 *   never this file. The accepted cost is that orphan rows are possible if a user is ever
 *   hard-deleted; nothing deletes users today, the `identity-challenge-pruning` job owed later (now
 *   owed by *two* tables) will sweep them, and the GDPR-erasure conversation before public launch
 *   must not forget either of them.
 * - **There is no index on `expires_at`.** Expiry is never a query predicate — the aggregate judges
 *   it in PHP against an instant from the `Clock` port, and the repository deliberately refuses to
 *   know what time it is. The pruning job will want one; it can add it, with a caller to justify it.
 */
final class Version20260729153542 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: create identity_password_reset_request (unique token_hash, composite (user_id, issued_at)) and add the nullable identity_user.password_changed_at. No foreign key between the two tables.';
    }

    public function up(Schema $schema): void
    {
        // Nullable with no default and no backfill — see the class docblock. Adding a nullable
        // column in PostgreSQL 11+ is a catalogue-only operation: no table rewrite, no long
        // ACCESS EXCLUSIVE lock, so this is safe to run against a live `identity_user`.
        $this->addSql(<<<'SQL'
            ALTER TABLE identity_user
                ADD password_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            SQL);

        // Timestamps are TIMESTAMP WITH TIME ZONE, as everywhere: the `Clock` port returns UTC and
        // Postgres stores an absolute instant, so a link issued by one container and redeemed by
        // another compares correctly no matter what timezone either had. The `(0)` precision is not
        // a limitation being accepted here but the shape being *stated* — Doctrine's
        // `datetimetz_immutable` formats through 'Y-m-d H:i:sO' and discards microseconds before
        // column precision is ever consulted, so widening this to TIMESTAMP(6) would change
        // nothing. ADR-0007 decision 8 is why the `Clock` contract mandates whole seconds instead of
        // letting the Domain hold precision the database silently drops — and it is what makes the
        // strict `>` in `isExpiredAt()` and the strict `<` in the I-23 staleness guard the right
        // operators rather than fussy ones.
        //
        // TWO nullable columns rather than one `closed_at` plus a reason. `redeemed_at` is the
        // single-use rule (I-16) in physical form: NULL means "still live", any value means "burnt,
        // and here is when". `invalidated_at` is the column `identity_email_verification_request`
        // has no need for, and its presence is the whole inversion this slice makes: issuing a new
        // verification link leaves the old ones alive because they are inert once any of them
        // verifies the address, while issuing a new *reset* link must kill the outstanding ones,
        // because each live reset token is an independent, repeatable chance to set a password.
        // Keeping the two apart is what lets `findOutstandingForUser()` filter on structure alone
        // and what lets the mutual exclusion I-17 live as two guards in the aggregate.
        //
        // `token_hash` is VARCHAR(255) and deliberately not CHAR(64): 64 lower-case hex characters
        // is what SHA-256 produces, and the digest algorithm is `RandomResetTokenGenerator`'s
        // choice, not the schema's. A column that encoded it would need migrating the day that
        // choice changes. The width mirrors `HashedResetToken::MAX_LENGTH`.
        //
        // `user_id` is a plain UUID column with no REFERENCES clause — see the class docblock.
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_password_reset_request (
                id             UUID                        NOT NULL,
                user_id        UUID                        NOT NULL,
                token_hash     VARCHAR(255)                NOT NULL,
                issued_at      TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                redeemed_at    TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                invalidated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // Serves the only hot query on this table — `findByTokenHash()`, run on every GET of a reset
        // link and again on the POST that spends it — and makes a duplicate digest a database-level
        // impossibility rather than a hope.
        //
        // Note the asymmetry with `uniq_identity_user_email`, which is the same kind of object doing
        // a different job. There, the index *is* invariant I-6 and its violation is a business event
        // that `DoctrineUserRepository::save()` translates into `EmailAlreadyRegistered`. Here a
        // violation would mean two independent draws of 256 CSPRNG bits collided, which is not a
        // business event at any probability worth naming, so
        // `DoctrinePasswordResetRequestRepository::save()` deliberately does not catch it: the
        // honest response is a 500 and an alert.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_identity_password_reset_request_token_hash
                ON identity_password_reset_request (token_hash)
            SQL);

        // One index, two queries. `countIssuedForUserSince()` (the anti-abuse rule I-21) matches
        // `user_id` for equality and `issued_at` as a range, and a B-tree can use a range predicate
        // only on the first column after all the equality ones — so this order scans a narrow slice
        // of one user's rows while the reverse would scan every user's rows inside the window.
        // `findOutstandingForUser()` then rides the leading column alone, and gets its
        // `ORDER BY issued_at` for free from the second.
        //
        // A partial index on `(user_id) WHERE redeemed_at IS NULL AND invalidated_at IS NULL` was
        // considered and rejected: the per-user row count is bounded at three per rolling hour and
        // the pruning job will keep it small, so the composite is already selective enough and a
        // second index would be pure write amplification.
        //
        // Both indexes are named explicitly rather than left to Doctrine's `IDX_<hash>` /
        // `UNIQ_<hash>` because the mapping, the adapter's comments and the AC-43 `EXPLAIN`
        // assertions all refer to them by name — and an assertion cannot refer to a name nobody
        // chose.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_identity_password_reset_request_user_issued
                ON identity_password_reset_request (user_id, issued_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // BOTH halves are reversed, in the mirror of the order `up()` applied them, and this is
        // worth checking rather than assuming: this slice ships exactly **one** migration, so
        // `doctrine:migrations:migrate prev` genuinely undoes it — unlike slice 2, where the last
        // migration applied was Messenger's and rolling back "the new table" took two steps. The
        // round trip is verified by hand (`migrate prev` then `migrate`), because a `down()` nobody
        // has ever run is a comment.
        //
        // Postgres drops an index together with the table that owns it, so the DROP TABLE fully
        // reverses three of the four statements. Separate DROP INDEX statements would be dead SQL a
        // future reader could mistake for a requirement.
        $this->addSql('DROP TABLE identity_password_reset_request');

        // The column is dropped too. Leaving it behind would be the tidier-looking choice and the
        // wrong one: after a rollback nothing writes it, so it would sit on `identity_user` as a
        // permanently-NULL column that the mapping no longer knows about, and the very next
        // `make migration.make` would offer to drop it anyway — as a surprise, in someone else's
        // slice.
        $this->addSql('ALTER TABLE identity_user DROP password_changed_at');
    }
}
