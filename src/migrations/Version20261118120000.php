<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `listing-attribute-values` (ADR-0020): a listing's "Details", one row per value.
 *
 * **Hand-written, not generated.** The table has no Doctrine mapping (the repository writes it, a
 * `postLoad` listener reads it — ADR-0020 decision 2) and `doctrine.dbal.schema_filter` hides it
 * from the diff, so `make migration.make` produces nothing for it in either direction. Its shape is
 * guarded only by AC-6's catalogue test: a column change is a hand-written migration plus that test.
 *
 * - One typed column per kind; the CHECK says exactly one is set (the aggregate's
 *   `AttributeValue` rule at rest). `value_int` is BIGINT: number bounds are PHP int64.
 * - PK `(listing_id, attribute_id)` is L-8's backstop and the load-by-listing index.
 * - `idx_listing_attribute_value_option` serves C-25/C-26 and the Phase 1 gate's facet count.
 * - **No foreign keys** (ADR-0009 decision 4); C-25/C-26/C-27 keep the references alive.
 * - Additive; no backfill (no value exists yet). Deploy window: until this runs, the edit page and
 *   the attribute/option/category guards answer 500. Seconds; accepted.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create listing_attribute_value';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE listing_attribute_value (listing_id UUID NOT NULL, attribute_id UUID NOT NULL, option_id UUID DEFAULT NULL, value_int BIGINT DEFAULT NULL, value_bool BOOLEAN DEFAULT NULL, value_text VARCHAR(200) DEFAULT NULL, PRIMARY KEY (listing_id, attribute_id), CONSTRAINT chk_listing_attribute_value_one_value CHECK (num_nonnulls(option_id, value_int, value_bool, value_text) = 1))');
        $this->addSql('CREATE INDEX idx_listing_attribute_value_option ON listing_attribute_value (attribute_id, option_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE listing_attribute_value');
    }
}
