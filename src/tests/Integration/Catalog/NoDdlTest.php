<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Handler\AddAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\RelabelAttributeOptionHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-27, ADR-0004's hard invariant as a test: no admin action ever runs DDL. Every attribute
 * handler is driven, and the columns and indexes of schema `public` are compared before and after.
 *
 * Under DAMA the whole test is one transaction. PostgreSQL DDL is transactional, so a stray
 * `ALTER TABLE` / `CREATE INDEX` inside a handler would be visible in the catalogs right here,
 * before the rollback, and the comparison would catch it.
 */
final class NoDdlTest extends KernelTestCase
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
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function catalogs(): array
    {
        return [
            $this->db->fetchAllAssociative("SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, ordinal_position FROM information_schema.columns WHERE table_schema = 'public' ORDER BY table_name, column_name"),
            $this->db->fetchAllAssociative("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename, indexname"),
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    public function testNoHandlerChangesTheColumnsOrIndexesOfThePublicSchema(): void
    {
        $category = CategoryFactory::createOne()->id()->toString();
        $before = $this->catalogs();
        $define = $this->service(DefineAttributeHandler::class);

        $choice = $define(new DefineAttribute($category, 'choice', 'Orientation', 'orientation', true, optionLabels: ['Left', 'Right']));
        $number = $define(new DefineAttribute($category, 'number', 'Scale', 'scale', false, 'mm', 500, 700));
        $define(new DefineAttribute($category, 'boolean', 'Has case', 'has-case', false));
        $text = $define(new DefineAttribute($category, 'text', 'Serial', 'serial', false));

        ($this->service(EditAttributeHandler::class))(new EditAttribute($number->toString(), 'Scale length', 'scale-length', true, 'cm', 50, 70));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($text->toString(), 'Serial no.', 'serial-no', false));

        $optionId = ($this->service(AddAttributeOptionHandler::class))(new AddAttributeOption($choice->toString(), 'Centre'));
        ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($choice->toString(), $optionId->toString(), 'Middle'));
        ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($choice->toString(), $optionId->toString()));
        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($choice->toString()));
        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($number->toString()));

        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute'), 'Control: the handlers really ran.');
        self::assertSame($before, $this->catalogs());
    }

    public function testTheComparisonWouldNoticeAnAddedColumn(): void
    {
        $before = $this->catalogs();

        $this->db->executeStatement('ALTER TABLE catalog_attribute ADD COLUMN probe_ddl integer');

        // Control: proves the snapshot is sensitive; rolled back with the test's transaction.
        self::assertNotSame($before, $this->catalogs());
    }

    public function testTheComparisonWouldNoticeAnAddedIndex(): void
    {
        $before = $this->catalogs();

        $this->db->executeStatement('CREATE INDEX probe_ddl_idx ON catalog_attribute (label)');

        self::assertNotSame($before, $this->catalogs());
    }
}
