<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The repository's first migration: the `Identity` context's user table.
 *
 * Purely additive and with no dependency on existing data — the table does not exist anywhere,
 * including production, so the expand/contract discipline has nothing to expand from yet.
 *
 * Two columns are worth explaining, because neither is used by anything this slice ships:
 *
 * - `email_verified_at` is here from day one although only the console command can set it. That
 *   is deliberate: `identity-email-verification` is the very next cycle and it must not need a
 *   migration against this table. Modelling the state now costs one nullable column; adding it
 *   later would cost a schema change plus a decision about what to backfill for existing rows.
 * - `roles` is JSONB rather than a join table. Roles are a tiny closed set with no attributes of
 *   their own, loaded on every authentication; a join table would add a query to every login to
 *   model a property as a relationship. See `RoleSetType` for the full reasoning, for why the
 *   binary `jsonb` form is chosen rather than inherited, and for the condition under which the
 *   whole choice should be revisited.
 */
final class Version20260725221800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: create identity_user with a unique index on email (invariant I-6).';
    }

    public function up(Schema $schema): void
    {
        // Timestamps are TIMESTAMP WITH TIME ZONE and the Clock port returns UTC, so Postgres
        // stores an absolute instant. A naive TIMESTAMP would make every stored moment depend on
        // whichever timezone the writing container happened to have.
        //
        // `roles` is JSONB, not JSON. Doctrine's PostgreSQL default for a JSON type is textual
        // `json`, which is a *derived* shape — and ADR-0007's whole point is that shapes are chosen.
        // `jsonb` parses once on write and stores a normalised binary form, which is what makes
        // containment (`@>`) and GIN indexes possible; textual `json` keeps the raw bytes and has to
        // reparse on every read. Nothing in this slice queries inside the array, but this is the
        // first table of seven contexts and this column is the convention they will copy.
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_user (
                id                UUID                        NOT NULL,
                email             VARCHAR(180)                NOT NULL,
                password_hash     VARCHAR(255)                NOT NULL,
                roles             JSONB                       NOT NULL,
                email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                registered_at     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // This index is invariant I-6 — email uniqueness — and it is the *only* real enforcement
        // of it: uniqueness spans every user, so no single aggregate can protect it. It also
        // serves the two hottest reads in the context, the registration pre-check and every
        // login. `DoctrineUserRepository::save()` translates its violation back into
        // `EmailAlreadyRegistered`.
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_user_email ON identity_user (email)');
    }

    public function down(Schema $schema): void
    {
        // Postgres drops an index together with the table that owns it, so this single statement
        // fully reverses `up()`. A separate DROP INDEX would be dead SQL that a future reader
        // could mistake for a requirement.
        $this->addSql('DROP TABLE identity_user');
    }
}
