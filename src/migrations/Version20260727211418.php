<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `Identity` context's second table: one row per issued email-verification challenge.
 *
 * Purely additive, and additive in the strongest sense the expand/contract discipline recognises:
 * it creates a table that exists nowhere, including production, and it does not touch a single
 * existing object. In particular **`identity_user` is untouched**, which is not luck. Slice 1
 * shipped `email_verified_at` in the very first migration precisely so that this slice would need
 * no schema change against the user table (its AC-23, this slice's AC-36) — a column costed at one
 * nullable field then, versus a schema change plus a backfill decision now. The generator agreed:
 * `make:migration` emitted three statements, all three against the new table. Had it wanted to
 * alter `identity_user`, that would have been a signal that something drifted rather than a diff to
 * accept.
 *
 * Two absences are decisions rather than omissions, and both would otherwise be "fixed" by a
 * well-meaning future reader:
 *
 * - **There is no foreign key on `user_id`** (ADR-0009 decision 4). Aggregates reference each other
 *   by identity, so referential integrity between two roots is the application's job, not the
 *   schema's. A hand-added FK would also be permanent noise: Doctrine's mapping does not know about
 *   it, so every subsequent `make migration.make` would diff it as an unwanted constraint and offer
 *   to drop it — deleted and re-added forever by whoever ran the generator last. The accepted cost
 *   is that orphan rows are possible if a user is ever hard-deleted; nothing deletes users today,
 *   the pruning job owed later will sweep them, and the GDPR-erasure conversation before public
 *   launch must not forget this table.
 * - **There is no backfill.** No verification request has ever existed, so there is nothing to
 *   migrate and no window in which old rows and new code disagree.
 */
final class Version20260727211418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: create identity_email_verification_request with a unique index on token_hash and a composite (user_id, issued_at) index. identity_user is not touched.';
    }

    public function up(Schema $schema): void
    {
        // Timestamps are TIMESTAMP WITH TIME ZONE, as everywhere: the `Clock` port returns UTC and
        // Postgres stores an absolute instant, so a link issued by one container and redeemed by
        // another compares correctly no matter what timezone either had. The `(0)` precision is not
        // a limitation being accepted here but the shape being *stated* — Doctrine's
        // `datetimetz_immutable` formats through 'Y-m-d H:i:sO' and discards microseconds before
        // column precision is ever consulted, so widening this to TIMESTAMP(6) would change
        // nothing. ADR-0007 decision 8 is why the `Clock` contract mandates whole seconds instead
        // of letting the Domain hold precision the database silently drops.
        //
        // `redeemed_at` is the only nullable column, and its nullability is the single-use rule
        // (invariant I-9) in physical form: NULL means "still live", any value means "burnt, and
        // here is when". A boolean flag would answer the invariant and throw the audit trail away.
        //
        // `token_hash` is VARCHAR(255) and deliberately not CHAR(64): 64 lower-case hex characters
        // is what SHA-256 produces, and the digest algorithm is `RandomVerificationTokenGenerator`'s
        // choice, not the schema's. A column that encoded it would need migrating the day that
        // choice changes. The width mirrors `HashedVerificationToken::MAX_LENGTH`.
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_email_verification_request (
                id          UUID                        NOT NULL,
                user_id     UUID                        NOT NULL,
                token_hash  VARCHAR(255)                NOT NULL,
                issued_at   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                redeemed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // Serves the only hot query on this table — `findByTokenHash()`, one lookup per click on a
        // verification link — and makes a duplicate digest a database-level impossibility rather
        // than a hope.
        //
        // Note the asymmetry with `uniq_identity_user_email`, which is the same kind of object
        // doing a different job. There, the index *is* invariant I-6 and its violation is a
        // business event that `DoctrineUserRepository::save()` translates into
        // `EmailAlreadyRegistered`. Here a violation would mean two independent draws of 256 CSPRNG
        // bits collided, which is not a business event at any probability worth naming, so
        // `DoctrineEmailVerificationRequestRepository::save()` deliberately does not catch it: the
        // honest response is a 500 and an alert.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_identity_email_verification_request_token_hash
                ON identity_email_verification_request (token_hash)
            SQL);

        // Serves `countIssuedForUserSince()`, the anti-abuse rule I-12. The column order is the
        // whole design: `user_id` leads because it is matched for equality and `issued_at` follows
        // because it is matched as a range — a B-tree can use a range predicate only on the first
        // column after all the equality ones, so this order scans a narrow slice of one user's rows
        // while the reverse would scan every user's rows inside the window.
        //
        // Both indexes are named explicitly rather than left to Doctrine's `IDX_<hash>` /
        // `UNIQ_<hash>` because the mapping, the adapter's comments and the AC-37 `EXPLAIN`
        // assertions all refer to them by name — and an assertion cannot refer to a name nobody
        // chose.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_identity_email_verification_request_user_issued
                ON identity_email_verification_request (user_id, issued_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Postgres drops an index together with the table that owns it, so this single statement
        // fully reverses `up()` — both indexes included. Separate DROP INDEX statements would be
        // dead SQL that a future reader could mistake for a requirement.
        $this->addSql('DROP TABLE identity_email_verification_request');
    }
}
