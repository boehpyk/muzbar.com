<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `identity-google-oauth` (ADR-0014): passwordless accounts and linked provider identities.
 *
 * Additive and backward compatible (expand only): existing rows keep their hash and get `[]`.
 *
 * The `DEFAULT '[]'` exists for one statement — it is what backfills the existing rows so `NOT NULL`
 * can hold — and is dropped immediately after. The mapping declares no default (the aggregate always
 * writes the value), so leaving it would make `make migration.make` propose dropping it forever.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make identity_user.password_hash nullable and add oauth_identities (Google sign-in)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN password_hash DROP NOT NULL');
        $this->addSql("ALTER TABLE identity_user ADD oauth_identities JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN oauth_identities DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // Restoring NOT NULL over a passwordless account would either fail midway or need the
        // account deleted. Neither is a rollback's call to make, so refuse instead.
        $passwordless = $this->connection->fetchOne('SELECT COUNT(*) FROM identity_user WHERE password_hash IS NULL');
        $this->abortIf((int) $passwordless > 0, 'Passwordless accounts exist; password_hash cannot become NOT NULL again.');

        $this->addSql('ALTER TABLE identity_user DROP oauth_identities');
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN password_hash SET NOT NULL');
    }
}
