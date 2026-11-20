<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-6: the migrated `listing_attribute_value` table, read from the PostgreSQL catalogs.
 *
 * The table is unmapped and hidden from Doctrine by `schema_filter` (ADR-0020), so no diff, no
 * `doctrine:schema:validate` and no mapping test can see it. These queries are its only shape guard.
 */
final class ListingAttributeValuesSchemaTest extends KernelTestCase
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
             WHERE table_schema = 'public' AND table_name = 'listing_attribute_value'
             ORDER BY column_name",
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[\is_string($row['column_name']) ? $row['column_name'] : '?'] = [$row['data_type'], $row['character_maximum_length'], $row['is_nullable']];
        }

        self::assertSame([
            'attribute_id' => ['uuid', null, 'NO'],
            'listing_id' => ['uuid', null, 'NO'],
            'option_id' => ['uuid', null, 'YES'],
            'value_bool' => ['boolean', null, 'YES'],
            'value_int' => ['bigint', null, 'YES'],
            'value_text' => ['character varying', 200, 'YES'],
        ], $actual);
    }

    public function testThePrimaryKeyIsListingThenAttribute(): void
    {
        $columns = $this->db->fetchFirstColumn(
            "SELECT a.attname FROM pg_constraint c
             CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
             JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
             WHERE c.conrelid = 'listing_attribute_value'::regclass AND c.contype = 'p'
             ORDER BY k.ord",
        );

        self::assertSame(['listing_id', 'attribute_id'], $columns);
    }

    public function testTheOnlySecondaryIndexIsAttributeThenOption(): void
    {
        $indexes = $this->db->fetchAllAssociative(
            "SELECT indexname, indexdef FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = 'listing_attribute_value' AND indexname <> 'listing_attribute_value_pkey'
             ORDER BY indexname",
        );

        self::assertCount(1, $indexes);
        self::assertSame('idx_listing_attribute_value_option', $indexes[0]['indexname']);
        self::assertStringEndsWith('(attribute_id, option_id)', \is_string($indexes[0]['indexdef']) ? $indexes[0]['indexdef'] : '');
        self::assertStringNotContainsString('UNIQUE', \is_string($indexes[0]['indexdef']) ? $indexes[0]['indexdef'] : '');
    }

    public function testThereIsExactlyOneCheckConstraintAndItIsTheOneValueRule(): void
    {
        $checks = $this->db->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conrelid = 'listing_attribute_value'::regclass AND contype = 'c'",
        );

        self::assertSame(['chk_listing_attribute_value_one_value'], array_keys($checks));
        $definition = $checks['chk_listing_attribute_value_one_value'];
        self::assertIsString($definition);
        self::assertStringContainsString('num_nonnulls(option_id, value_int, value_bool, value_text)', $definition);
        self::assertStringContainsString('= 1', $definition);
    }

    public function testThereIsNoForeignKeyInEitherDirection(): void
    {
        $count = $this->db->fetchOne(
            "SELECT count(*) FROM pg_constraint
             WHERE contype = 'f' AND (conrelid = 'listing_attribute_value'::regclass OR confrelid = 'listing_attribute_value'::regclass)",
        );

        self::assertSame(0, $count);
    }

    public function testARowWithExactlyOneTypedColumnIsAccepted(): void
    {
        $this->insert(['value_int' => 6]);

        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value'));
    }

    /**
     * @return iterable<string, array{array<string, int|string|bool>}>
     */
    public static function rowsTheCheckMustRefuse(): iterable
    {
        yield 'two typed columns' => [['value_int' => 6, 'value_text' => 'six']];
        yield 'an option and a boolean' => [['option_id' => '018f5b2a-0000-7000-8000-00000000b001', 'value_bool' => true]];
        yield 'no typed column at all' => [[]];
    }

    /**
     * @param array<string, int|string|bool> $typed
     */
    #[DataProvider('rowsTheCheckMustRefuse')]
    public function testARowWithTwoTypedColumnsOrNoneIsRefusedWithCheckViolation(array $typed): void
    {
        try {
            // A nested transaction is a savepoint: the failed statement must not poison DAMA's outer one.
            $this->db->transactional(fn () => $this->insert($typed));
            self::fail('The CHECK accepted a row that does not hold exactly one value.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState());
        }

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value'));
    }

    public function testTheSameAttributeTwiceForOneListingIsRefusedByThePrimaryKey(): void
    {
        $listing = Uuid::v7()->toRfc4122();
        $attribute = Uuid::v7()->toRfc4122();
        $this->insert(['value_int' => 1], $listing, $attribute);

        try {
            $this->db->transactional(fn () => $this->insert(['value_int' => 2], $listing, $attribute));
            self::fail('L-8 has no backstop: two values for one attribute were stored.');
        } catch (DriverException $e) {
            self::assertSame('23505', $e->getSQLState());
        }
    }

    public function testTheMappingsAndTheDatabaseAgreeSoAMigrationDiffNeverMentionsTheTable(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $statements = (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());
        $mine = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'listing_attribute_value')));

        self::assertSame([], $mine, 'schema_filter must hide the unmapped table, or every migration diff proposes dropping it.');
    }

    /**
     * @param array<string, int|string|bool> $typed
     */
    private function insert(array $typed, ?string $listingId = null, ?string $attributeId = null): void
    {
        $row = ['listing_id' => $listingId ?? Uuid::v7()->toRfc4122(), 'attribute_id' => $attributeId ?? Uuid::v7()->toRfc4122(), ...$typed];
        $types = array_map(static fn (int|string|bool $v): string => \is_bool($v) ? 'boolean' : (\is_int($v) ? 'integer' : 'string'), $row);

        $this->db->insert('listing_attribute_value', $row, array_values($types));
    }
}
