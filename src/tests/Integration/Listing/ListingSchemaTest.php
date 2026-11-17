<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-20: the migrated `listing_listing` table, read from the PostgreSQL catalogs.
 */
final class ListingSchemaTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    public function testTheColumnsHaveExactlyTheSpecifiedTypesAndNullability(): void
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT column_name, data_type, character_maximum_length, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'listing_listing'
             ORDER BY column_name",
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[\is_string($row['column_name']) ? $row['column_name'] : '?'] = [$row['data_type'], $row['character_maximum_length'], $row['is_nullable']];
        }

        self::assertSame([
            'category_id' => ['uuid', null, 'NO'],
            'description' => ['character varying', 5000, 'NO'],
            'id' => ['uuid', null, 'NO'],
            'price_amount' => ['bigint', null, 'NO'],
            'price_currency' => ['character varying', 3, 'NO'],
            'seller_id' => ['uuid', null, 'NO'],
            'status' => ['character varying', 16, 'NO'],
            'title' => ['character varying', 120, 'NO'],
        ], $actual);
    }

    public function testThePrimaryKeyIsId(): void
    {
        $columns = $this->db->fetchFirstColumn(
            "SELECT a.attname FROM pg_constraint c
             JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
             WHERE c.conrelid = 'listing_listing'::regclass AND c.contype = 'p'",
        );

        self::assertSame(['id'], $columns);
    }

    public function testTheOnlySecondaryIndexesAreSellerAndCategory(): void
    {
        $indexes = $this->db->fetchAllAssociative(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'listing_listing' AND indexname <> 'listing_listing_pkey' ORDER BY indexname",
        );

        self::assertCount(2, $indexes);
        self::assertSame('idx_listing_listing_category', $indexes[0]['indexname']);
        self::assertStringEndsWith('(category_id)', \is_string($indexes[0]['indexdef']) ? $indexes[0]['indexdef'] : '');
        self::assertSame('idx_listing_listing_seller', $indexes[1]['indexname']);
        self::assertStringEndsWith('(seller_id)', \is_string($indexes[1]['indexdef']) ? $indexes[1]['indexdef'] : '');
    }

    public function testThereIsNoForeignKeyInEitherDirection(): void
    {
        $count = $this->db->fetchOne(
            "SELECT count(*) FROM pg_constraint
             WHERE contype = 'f' AND (conrelid = 'listing_listing'::regclass OR confrelid = 'listing_listing'::regclass)",
        );

        self::assertSame(0, $count);
    }

    public function testTheMappingAndTheTableAgreeSoAMigrationDiffWouldBeEmpty(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        $mine = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'listing_listing')));

        self::assertSame([], $mine);
    }
}
