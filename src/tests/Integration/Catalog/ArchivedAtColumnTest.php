<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Tests\Factory\AttributeFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * listing-schema-drift AC-8: the shape of `archived_at` on both Catalog tables, against the real
 * database (the column's own shape; the column list of each table is guarded in its repository test).
 */
final class ArchivedAtColumnTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        yield 'attribute' => ['catalog_attribute'];
        yield 'option' => ['catalog_attribute_option'];
    }

    #[DataProvider('tables')]
    public function testArchivedAtIsAWholeSecondNullableTimestampWithTimeZoneDefaultingToNull(string $table): void
    {
        $column = $this->db->fetchAssociative(
            "SELECT data_type, datetime_precision, is_nullable, column_default FROM information_schema.columns WHERE table_name = ? AND column_name = 'archived_at'",
            [$table],
        );

        self::assertIsArray($column);
        self::assertSame('timestamp with time zone', $column['data_type']);
        self::assertSame(0, $column['datetime_precision']);
        self::assertSame('YES', $column['is_nullable']);
        self::assertContains($column['column_default'], [null, 'NULL::timestamp with time zone']);
    }

    #[DataProvider('tables')]
    public function testNoIndexMentionsArchivedAt(string $table): void
    {
        $defs = $this->db->fetchFirstColumn('SELECT indexdef FROM pg_indexes WHERE tablename = ?', [$table]);

        self::assertNotSame([], $defs);

        foreach ($defs as $def) {
            self::assertIsString($def);
            self::assertStringNotContainsString('archived_at', $def);
        }
    }

    public function testEveryRowBuiltWithoutArchivingHasANullArchivedAt(): void
    {
        AttributeFactory::new()->choice(['A', 'B'])->create();

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute WHERE archived_at IS NOT NULL'));
        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE archived_at IS NOT NULL'));
    }

    public function testTheMigrationBackfillsNothingSoEveryPreExistingRowIsActive(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 3).'/migrations/Version20261121224833.php');
        self::assertIsString($source);
        $statements = [];
        preg_match_all("/addSql\\('([^']*)'\\)/", $source, $statements);

        self::assertCount(4, $statements[1], 'Two ADDs in up(), two DROPs in down().');

        foreach ($statements[1] as $sql) {
            self::assertMatchesRegularExpression('/^ALTER TABLE catalog_attribute(_option)? (ADD archived_at TIMESTAMP\(0\) WITH TIME ZONE DEFAULT NULL|DROP archived_at)$/', $sql);
        }
    }
}
