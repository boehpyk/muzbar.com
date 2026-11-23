<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `search-full-text` (ADR-0025): Search's first database objects — the text index over listings.
 *
 * **Hand-written, not generated.** Doctrine reads none of this: extensions, functions and triggers
 * are not schema assets it introspects, and `doctrine.dbal.schema_filter` hides `search_listing_text`
 * (an unfiltered table is diffed as `DROP TABLE`). Its shape is guarded only by
 * `SearchTextSchemaTest` (AC-6): any change here is a hand-written migration plus that test.
 *
 * - **One mangling (S-16).** `search_fold()` is the only fold and `'simple'` the only configuration;
 *   the trigger (write side) and `search_tsquery()` (read side) both use them, here, in one file.
 *   Calls are schema-qualified (`public.…`) so a `pg_restore` with an empty `search_path` behaves the
 *   same; no `SET search_path` on the SQL functions, which would stop the planner folding them into
 *   an `Index Cond`.
 * - **The parser builds the query (ADR-0025 d.3).** `plainto_tsquery` never reads its input as
 *   operators — not even what the fold makes of `ʼ` or `ː` — and `:*` is appended to PostgreSQL's
 *   own canonical output, so only its last lexeme becomes a prefix.
 * - **One writer (S-19).** The trigger, in the writer's transaction, for every path that writes a
 *   listing. `UPDATE OF title, description` only: publish/withdraw do not fire it.
 * - **The FK** is a derivation's, not an inter-aggregate reference (ADR-0009 is about aggregates):
 *   the text goes with its listing.
 * - **The backfill fires the trigger** rather than repeating its expression, so it cannot disagree
 *   with the write path; it is also the runbook's rebuild. `CREATE TRIGGER` holds `SHARE ROW
 *   EXCLUSIVE` on `listing_listing` until this transaction commits, so no write slips between them.
 * - **Watch item:** a rename or type change of `listing_listing.title`/`description` must redefine
 *   `search_listing_text_sync()` in the same migration — the trigger breaks at the next write.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the full-text index: unaccent, pg_trgm, search_fold(), search_tsquery(), search_listing_text and its sync trigger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION search_fold(text) RETURNS text
              LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
              AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION search_tsquery(text) RETURNS tsquery
              LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
              AS $$ SELECT CASE WHEN numnode(t) = 0 THEN t ELSE (t::text || ':*')::tsquery END
                    FROM plainto_tsquery('simple', public.search_fold($1)) AS t $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE search_listing_text (
              listing_id   uuid PRIMARY KEY REFERENCES listing_listing (id) ON DELETE CASCADE,
              document     tsvector NOT NULL,
              title_folded text NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_search_listing_text_document ON search_listing_text USING gin (document)');
        $this->addSql('CREATE INDEX idx_search_listing_text_title ON search_listing_text USING gin (title_folded gin_trgm_ops)');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION search_listing_text_sync() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              INSERT INTO search_listing_text (listing_id, document, title_folded)
              VALUES (NEW.id,
                      to_tsvector('simple', public.search_fold(NEW.title || ' ' || NEW.description)),
                      public.search_fold(NEW.title))
              ON CONFLICT (listing_id) DO UPDATE
                SET document = EXCLUDED.document, title_folded = EXCLUDED.title_folded;
              RETURN NULL;
            END $$
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_search_listing_text
              AFTER INSERT OR UPDATE OF title, description ON listing_listing
              FOR EACH ROW EXECUTE FUNCTION search_listing_text_sync()
            SQL);

        // The backfill IS the write path (and the runbook's rebuild).
        $this->addSql('UPDATE listing_listing SET title = title');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_search_listing_text ON listing_listing');
        $this->addSql('DROP FUNCTION search_listing_text_sync()');
        $this->addSql('DROP TABLE search_listing_text');
        $this->addSql('DROP FUNCTION search_tsquery(text)');
        $this->addSql('DROP FUNCTION search_fold(text)');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
