<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-16, AC-18 (attribute half), AC-28, AC-29 and AC-31 for `catalog_attribute`: the adapter and
 * the table behind it, against the real database.
 */
final class DoctrineAttributeRepositoryTest extends KernelTestCase
{
    private AttributeRepository $attributes;
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $attributes = self::getContainer()->get(AttributeRepository::class);
        self::assertInstanceOf(AttributeRepository::class, $attributes);
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->attributes = $attributes;
        $this->db = $db;
        $this->em = $em;
    }

    private function insertRaw(string $categoryId, string $code, string $type = 'text', ?string $numberRules = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->db->executeStatement(
            'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required, number_rules) VALUES (?, ?, ?, ?, ?, false, ?)',
            [$id, $categoryId, $code, ucfirst($code), $type, $numberRules],
        );

        return $id;
    }

    // ---------------------------------------------------------------- AC-16

    public function testARawDuplicateCategoryAndCodeViolatesTheUniqueIndex(): void
    {
        $category = CategoryFactory::createOne()->id()->toString();
        $this->insertRaw($category, 'string-count');

        try {
            $this->db->transactional(fn () => $this->insertRaw($category, 'string-count'));
            self::fail('Expected a unique violation.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSaveTranslatesAUniqueViolationIntoAttributeCodeAlreadyTaken(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->coded('string-count')->create();

        $duplicate = Attribute::defineText(
            $this->attributes->nextIdentity(),
            $category->id(),
            AttributeCode::fromString('string-count'),
            AttributeLabel::fromString('String count again'),
            false,
            AttributeCodes::none(),
        );

        $this->expectException(AttributeCodeAlreadyTaken::class);
        $this->expectExceptionMessage('string-count');

        // The pre-check is bypassed on purpose: this is the backstop.
        $this->attributes->save($duplicate);
    }

    public function testTheSameCodeInAnotherCategoryIsNotAViolation(): void
    {
        AttributeFactory::new()->inCategory(CategoryFactory::createOne())->coded('string-count')->create();
        $other = CategoryFactory::createOne();

        $this->attributes->save(Attribute::defineText(
            $this->attributes->nextIdentity(),
            $other->id(),
            AttributeCode::fromString('string-count'),
            AttributeLabel::fromString('String count'),
            false,
            AttributeCodes::none(),
        ));

        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute'));
    }

    // ---------------------------------------------------------------- reads

    public function testGetReturnsAnAggregateEqualToTheOneSaved(): void
    {
        $category = CategoryFactory::createOne();
        $saved = AttributeFactory::new()->inCategory($category)->number(NumberRules::of(Unit::fromString('mm'), 5, 10))->coded('scale')->labelled('Scale')->create();
        $this->em->clear();

        $loaded = $this->attributes->get($saved->id());

        self::assertTrue($loaded->categoryId()->equals($category->id()));
        self::assertSame('scale', $loaded->code()->toString());
        self::assertSame('Scale', $loaded->label()->toString());
        self::assertSame(AttributeType::Number, $loaded->type());
        self::assertSame('mm', $loaded->numberRules()?->unit()?->toString());
        self::assertSame(5, $loaded->numberRules()->minimum());
        self::assertSame(10, $loaded->numberRules()->maximum());
    }

    public function testGetOfAnUnknownIdIsAttributeNotFound(): void
    {
        $this->expectException(AttributeNotFound::class);

        $this->attributes->get(AttributeId::fromString(Uuid::v7()->toRfc4122()));
    }

    public function testExistsForCategory(): void
    {
        $with = CategoryFactory::createOne();
        $without = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($with)->create();

        self::assertTrue($this->attributes->existsForCategory($with->id()));
        self::assertFalse($this->attributes->existsForCategory($without->id()));
    }

    public function testRemoveDeletesTheRow(): void
    {
        $attribute = AttributeFactory::createOne();

        $this->attributes->remove($attribute);

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute'));
    }

    // ---------------------------------------------------------------- AC-28

    public function testACorruptNumberRulesRowMakesGetThrowAConversionException(): void
    {
        $id = $this->insertRaw(CategoryFactory::createOne()->id()->toString(), 'broken', 'number', '{"min":"x"}');

        $this->expectException(ValueNotConvertible::class);

        $this->attributes->get(AttributeId::fromString($id));
    }

    public function testARowWhoseStoredMinimumExceedsItsMaximumIsAlsoLoud(): void
    {
        $id = $this->insertRaw(CategoryFactory::createOne()->id()->toString(), 'inverted', 'number', '{"unit":null,"min":9,"max":1}');

        $this->expectException(ValueNotConvertible::class);

        $this->attributes->get(AttributeId::fromString($id));
    }

    // ---------------------------------------------------------------- AC-29, AC-31

    public function testTheTableHasTheSpecifiedColumnsAndNoForeignKey(): void
    {
        $columns = $this->db->fetchAllKeyValue(
            "SELECT column_name, data_type || ':' || coalesce(character_maximum_length::text, '') || ':' || is_nullable
             FROM information_schema.columns WHERE table_name = 'catalog_attribute'",
        );

        self::assertSame([
            'id' => 'uuid::NO',
            'category_id' => 'uuid::NO',
            'code' => 'character varying:64:NO',
            'label' => 'character varying:100:NO',
            'type' => 'character varying:16:NO',
            'is_required' => 'boolean::NO',
            'number_rules' => 'jsonb::YES',
        ], $columns);
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM pg_constraint WHERE conrelid = 'catalog_attribute'::regclass AND contype = 'f'"));
        self::assertSame('id', $this->db->fetchOne("SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) WHERE i.indrelid = 'catalog_attribute'::regclass AND i.indisprimary"));
    }

    public function testTheUniqueIndexOnCategoryAndCodeExistsAndIsPlain(): void
    {
        /** @var array<string, string> $indexes */
        $indexes = $this->db->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'catalog_attribute'");

        self::assertArrayHasKey('uniq_catalog_attribute_category_code', $indexes);
        self::assertStringContainsString('UNIQUE', $indexes['uniq_catalog_attribute_category_code']);
        self::assertStringContainsString('(category_id, code)', $indexes['uniq_catalog_attribute_category_code']);
        self::assertStringNotContainsString('WHERE', $indexes['uniq_catalog_attribute_category_code']);
        self::assertCount(2, $indexes, 'Only the primary key and the unique index: no expression or extra index.');
    }

    public function testNextIdentityMintsUuidV7sInIncreasingOrder(): void
    {
        $first = $this->attributes->nextIdentity();
        $second = $this->attributes->nextIdentity();

        self::assertSame('7', $first->toString()[14]);
        self::assertLessThan(0, strcmp($first->toString(), $second->toString()));
    }

    public function testTheAttributeDbalTypesAreRegistered(): void
    {
        foreach (['catalog_attribute_id', 'catalog_attribute_option_id', 'catalog_attribute_code', 'catalog_attribute_label', 'catalog_attribute_number_rules'] as $name) {
            self::assertTrue(Type::hasType($name), $name);
        }
    }

    // ---------------------------------------------------------------- read model (effectiveFor of a root: AC-8 ordering)

    public function testTheReadModelListsAttributesByLabelCaseInsensitivelyWithTheOptionCountsInOneRow(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->labelled('banana')->choice(['A', 'B', 'C'])->create();
        AttributeFactory::new()->inCategory($category)->labelled('Cherry')->text()->create();
        AttributeFactory::new()->inCategory($category)->labelled('Apple')->number(NumberRules::of(Unit::fromString('kg'), 1, 5))->create();
        AttributeFactory::new()->labelled('Elsewhere')->create();

        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($category->id()->toString());

        self::assertSame(['Apple', 'banana', 'Cherry'], array_column($rows, 'label'));
        self::assertSame(['number', 'choice', 'text'], array_column($rows, 'type'));
        self::assertSame([0, 3, 0], array_map(intval(...), array_column($rows, 'optionCount')));
        self::assertSame('kg', $rows[0]['unit']);
        self::assertSame(1, $rows[0]['minimum']);
        self::assertSame(5, $rows[0]['maximum']);
    }

    public function testTheReadModelBreaksLabelTiesById(): void
    {
        $category = CategoryFactory::createOne();
        $first = AttributeFactory::new()->inCategory($category)->labelled('Same')->create();
        $second = AttributeFactory::new()->inCategory($category)->labelled('same')->create();

        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($category->id()->toString());

        self::assertSame([$first->id()->toString(), $second->id()->toString()], array_column($rows, 'id'));
    }

    public function testTheReadModelReadsBoundsBeyondTheInt4Range(): void
    {
        $category = CategoryFactory::createOne();
        $attribute = AttributeFactory::new()->inCategory($category)->number(NumberRules::of(null, -3_000_000_000, 3_000_000_000))->create();
        $schema = new DbalAttributeSchema($this->db);

        $listed = $schema->effectiveFor($category->id()->toString());
        $found = $schema->find($attribute->id()->toString());

        self::assertSame([-3_000_000_000, 3_000_000_000], [$listed[0]['minimum'], $listed[0]['maximum']]);
        self::assertSame([-3_000_000_000, 3_000_000_000], [$found['minimum'] ?? null, $found['maximum'] ?? null]);
    }

    public function testFindReturnsNullForAnUnknownId(): void
    {
        self::assertNull((new DbalAttributeSchema($this->db))->find(Uuid::v7()->toRfc4122()));
    }
}
