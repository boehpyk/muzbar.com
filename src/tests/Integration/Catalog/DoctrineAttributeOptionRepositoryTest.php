<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Port\AttributeOptionRepository;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\AttributeOptionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-13 (read order), AC-18, AC-23's sweep and AC-29 for `catalog_attribute_option`.
 */
final class DoctrineAttributeOptionRepositoryTest extends KernelTestCase
{
    private AttributeOptionRepository $options;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $options = self::getContainer()->get(AttributeOptionRepository::class);
        self::assertInstanceOf(AttributeOptionRepository::class, $options);
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->options = $options;
        $this->db = $db;
    }

    private function insertRaw(string $attributeId, string $label): void
    {
        $this->db->executeStatement(
            'INSERT INTO catalog_attribute_option (id, attribute_id, label, label_key) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $attributeId, $label, AttributeLabel::fromString($label)->key()],
        );
    }

    // ---------------------------------------------------------------- AC-18

    public function testARawDuplicateAttributeAndLabelKeyViolatesTheUniqueIndex(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();

        try {
            $this->db->transactional(fn () => $this->insertRaw($attribute->id()->toString(), 'Right'));
            self::fail('Expected a unique violation.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSaveTranslatesAUniqueViolationIntoDuplicateAttributeOption(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();
        // Built straight from the aggregate, bypassing the handler's pre-check on purpose.
        $duplicate = $attribute->newOption($this->options->nextIdentity(), AttributeLabel::fromString('RIGHT'));

        try {
            $this->options->save($duplicate);
            self::fail('Expected DuplicateAttributeOption.');
        } catch (DuplicateAttributeOption $e) {
            self::assertSame('RIGHT', $e->label()->toString());
        }
    }

    public function testRelabellingOntoAnotherOptionsKeyIsTranslatedToo(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right', 'Left'])->create();
        $left = $this->options->get(AttributeOptionId::fromString($this->idOf($attribute->id()->toString(), 'Left')));
        $left->relabel(AttributeLabel::fromString('right'));

        $this->expectException(DuplicateAttributeOption::class);

        $this->options->save($left);
    }

    private function idOf(string $attributeId, string $label): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$attributeId, $label]);
        self::assertIsString($id);

        return $id;
    }

    // ---------------------------------------------------------------- queries

    public function testIsLabelTakenComparesCaseInsensitivelyAndExcludesTheOptionItself(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();
        $other = AttributeFactory::new()->choice(['Left'])->create();
        $right = AttributeOptionId::fromString($this->idOf($attribute->id()->toString(), 'Right'));

        self::assertTrue($this->options->isLabelTaken($attribute->id(), AttributeLabel::fromString('RIGHT'), null));
        self::assertFalse($this->options->isLabelTaken($attribute->id(), AttributeLabel::fromString('RIGHT'), $right));
        self::assertFalse($this->options->isLabelTaken($other->id(), AttributeLabel::fromString('Right'), null));
    }

    public function testCountActiveForAttributeCountsOnlyItsOwnOptions(): void
    {
        $three = AttributeFactory::new()->choice(['A', 'B', 'C'])->create();
        AttributeFactory::new()->choice(['X'])->create();

        self::assertSame(3, $this->options->countActiveForAttribute($three->id()));
    }

    public function testGetOfAnUnknownIdIsOptionNotFound(): void
    {
        $this->expectException(AttributeOptionNotFound::class);

        $this->options->get(AttributeOptionId::fromString(Uuid::v7()->toRfc4122()));
    }

    public function testSaveWritesAWholeBatchInOneCall(): void
    {
        $attribute = AttributeFactory::new()->choice(['Seed'])->create();
        $batch = array_map(
            fn (string $label) => $attribute->newOption($this->options->nextIdentity(), AttributeLabel::fromString($label)),
            ['One', 'Two', 'Three'],
        );

        $this->options->save(...$batch);

        self::assertSame(4, $this->options->countActiveForAttribute($attribute->id()));
    }

    // ---------------------------------------------------------------- AC-13 order

    public function testTheReadModelListsOptionsInInsertionOrderNotAlphabetically(): void
    {
        $attribute = AttributeFactory::new()->choice(['Zulu', 'Alpha', 'Mike'])->create();

        $options = (new DbalAttributeSchema($this->db))->optionsOf($attribute->id()->toString());

        self::assertSame(['Zulu', 'Alpha', 'Mike'], array_column($options, 'label'));
    }

    // ---------------------------------------------------------------- AC-23 sweep

    public function testRemoveAllForAttributeDeletesOnlyThatAttributesOptions(): void
    {
        $doomed = AttributeFactory::new()->choice(['A', 'B', 'C'])->create();
        $kept = AttributeFactory::new()->choice(['X', 'Y'])->create();

        $this->options->removeAllForAttribute($doomed->id());

        // Plain SQL: read back through DBAL, not through the identity map that still holds them.
        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$doomed->id()->toString()]));
        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$kept->id()->toString()]));
    }

    public function testRemoveDeletesTheRow(): void
    {
        $attribute = AttributeFactory::new()->choice(['A'])->create();
        $option = AttributeOptionFactory::new()->forAttribute($attribute)->labelled('B')->create();

        $this->options->remove($option);

        self::assertSame(['A'], $this->db->fetchFirstColumn('SELECT label FROM catalog_attribute_option WHERE attribute_id = ?', [$attribute->id()->toString()]));
    }

    // ---------------------------------------------------------------- AC-29

    public function testTheTableHasTheSpecifiedColumnsAndNoForeignKey(): void
    {
        $columns = $this->db->fetchAllKeyValue(
            "SELECT column_name, data_type || ':' || coalesce(character_maximum_length::text, '') || ':' || is_nullable
             FROM information_schema.columns WHERE table_name = 'catalog_attribute_option'",
        );

        self::assertSame([
            'id' => 'uuid::NO',
            'attribute_id' => 'uuid::NO',
            'label' => 'character varying:100:NO',
            'label_key' => 'character varying:100:NO',
            // listing-schema-drift (C-28): null while active.
            'archived_at' => 'timestamp with time zone::YES',
        ], $columns);
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM pg_constraint WHERE conrelid = 'catalog_attribute_option'::regclass AND contype = 'f'"));
    }

    public function testTheUniqueIndexOnAttributeAndLabelKeyExistsAndIsPlain(): void
    {
        /** @var array<string, string> $indexes */
        $indexes = $this->db->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'catalog_attribute_option'");

        self::assertArrayHasKey('uniq_catalog_attribute_option_label', $indexes);
        self::assertStringContainsString('UNIQUE', $indexes['uniq_catalog_attribute_option_label']);
        self::assertStringContainsString('(attribute_id, label_key)', $indexes['uniq_catalog_attribute_option_label']);
        self::assertCount(2, $indexes, 'Only the primary key and the unique index: no expression or extra index.');
    }

    public function testTheStoredLabelKeyIsTheCaseFoldedLabel(): void
    {
        $attribute = AttributeFactory::new()->choice(['LEFT-Handed', 'Ёлка'])->create();

        self::assertSame(
            ['left-handed', 'ёлка'],
            $this->db->fetchFirstColumn('SELECT label_key FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id', [$attribute->id()->toString()]),
        );
    }

    // listing-schema-drift: the factory builds an archived option through archive(), and the column carries it.
    public function testAnArchivedFactoryOptionStoresItsArchiveInstantAndReloadsArchived(): void
    {
        $at = new \DateTimeImmutable('2026-11-21 10:00:00', new \DateTimeZone('UTC'));
        $attribute = AttributeFactory::new()->choice(['Keep'])->create();
        $option = AttributeOptionFactory::new()->forAttribute($attribute)->labelled('Gone')->archived($at)->create();

        self::assertSame('2026-11-21 10:00:00+00', $this->db->fetchOne('SELECT archived_at FROM catalog_attribute_option WHERE id = ?', [$option->id()->toString()]));
        self::assertSame(1, $this->options->countActiveForAttribute($attribute->id()));
    }
}
