<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-6 (S-19): the full-text index's database objects, read from the PostgreSQL catalogs.
 *
 * `search_listing_text` is unmapped and hidden by `schema_filter`, and Doctrine reads neither
 * extensions, functions nor triggers (ADR-0025) — so no diff, no `doctrine:schema:validate` and no
 * mapping test can see any of it. These queries are its only shape guard.
 */
final class SearchTextSchemaTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    public function testBothExtensionsAreInstalled(): void
    {
        $installed = $this->db->fetchFirstColumn(
            "SELECT extname FROM pg_extension WHERE extname IN ('unaccent', 'pg_trgm') ORDER BY extname",
        );

        self::assertSame(['pg_trgm', 'unaccent'], $installed);
    }

    public function testTheFoldAndTheQueryBuilderExistAndAreImmutable(): void
    {
        $functions = $this->db->fetchAllKeyValue(
            "SELECT p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')', p.provolatile
             FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname IN ('search_fold', 'search_tsquery')
             ORDER BY 1",
        );

        // 'i' = IMMUTABLE: what lets the planner fold them into an index condition.
        self::assertSame(['search_fold(text)' => 'i', 'search_tsquery(text)' => 'i'], $functions);
    }

    public function testBothSidesUseTheSimpleConfiguration(): void
    {
        $bodies = $this->db->fetchAllKeyValue(
            "SELECT p.proname, p.prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname IN ('search_tsquery', 'search_listing_text_sync')
             ORDER BY 1",
        );

        self::assertCount(2, $bodies);
        foreach ($bodies as $name => $body) {
            self::assertIsString($body);
            self::assertStringContainsString("'simple'", $body, $name.' must use the one configuration (S-16).');
            self::assertStringContainsString('search_fold(', $body, $name.' must use the one fold (S-16).');
        }
    }

    public function testTheColumnsHaveExactlyTheSpecifiedTypesAndNullability(): void
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'search_listing_text'
             ORDER BY column_name",
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[\is_string($row['column_name']) ? $row['column_name'] : '?'] = [$row['data_type'], $row['is_nullable']];
        }

        self::assertSame([
            'document' => ['tsvector', 'NO'],
            'listing_id' => ['uuid', 'NO'],
            'title_folded' => ['text', 'NO'],
        ], $actual);
    }

    public function testThePrimaryKeyIsTheListingId(): void
    {
        $columns = $this->db->fetchFirstColumn(
            "SELECT a.attname FROM pg_constraint c
             CROSS JOIN LATERAL unnest(c.conkey) AS k(attnum)
             JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
             WHERE c.conrelid = 'search_listing_text'::regclass AND c.contype = 'p'",
        );

        self::assertSame(['listing_id'], $columns);
    }

    public function testTheOnlyForeignKeyCascadesFromTheListing(): void
    {
        $definitions = $this->db->fetchFirstColumn(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint
             WHERE contype = 'f' AND conrelid = 'search_listing_text'::regclass",
        );

        self::assertSame(['FOREIGN KEY (listing_id) REFERENCES listing_listing(id) ON DELETE CASCADE'], $definitions);
    }

    public function testTheSecondaryIndexesAreTheTwoGinIndexes(): void
    {
        $indexes = $this->db->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = 'search_listing_text' AND indexname <> 'search_listing_text_pkey'
             ORDER BY indexname",
        );

        self::assertSame(['idx_search_listing_text_document', 'idx_search_listing_text_title'], array_keys($indexes));
        $document = $indexes['idx_search_listing_text_document'];
        $title = $indexes['idx_search_listing_text_title'];
        self::assertIsString($document);
        self::assertIsString($title);
        self::assertStringEndsWith('USING gin (document)', $document);
        self::assertStringEndsWith('USING gin (title_folded gin_trgm_ops)', $title);
    }

    public function testOneRowLevelTriggerSyncsOnInsertAndOnTitleOrDescriptionUpdates(): void
    {
        $triggers = $this->db->fetchFirstColumn(
            "SELECT pg_get_triggerdef(oid) FROM pg_trigger
             WHERE tgrelid = 'listing_listing'::regclass AND NOT tgisinternal",
        );

        self::assertSame(
            ['CREATE TRIGGER trg_search_listing_text AFTER INSERT OR UPDATE OF title, description ON public.listing_listing FOR EACH ROW EXECUTE FUNCTION search_listing_text_sync()'],
            $triggers,
        );
    }

    public function testTheMappingsAndTheDatabaseAgreeSoAMigrationDiffNeverMentionsTheTable(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        $mine = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'search_listing_text')));

        self::assertSame([], $mine, 'schema_filter must hide the unmapped table, or every migration diff proposes dropping it.');
    }
}
