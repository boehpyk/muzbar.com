<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Handler\AddAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Catalog\Handler\RelabelAttributeOptionHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidNumberRules;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeRepository;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Fixture\RacingAttributeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-12 … AC-25: the six attribute handlers against the real `muzbar_test` database (DAMA
 * rollback). Rows are read back through DBAL, never through a repository that may be serving
 * Doctrine's identity map; "unchanged" means both whole tables compared before and after.
 */
final class AttributeHandlersTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->db = $db;
        $this->em = $em;
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

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute ORDER BY id'),
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option ORDER BY id'),
        ];
    }

    private function rows(string $table): int
    {
        $count = $this->db->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function optionLabels(string $attributeId): array
    {
        /** @var list<string> $labels */
        $labels = $this->db->fetchFirstColumn('SELECT label FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id', [$attributeId]);

        return $labels;
    }

    private function optionId(string $attributeId, string $label): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$attributeId, $label]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $optionLabels
     */
    private function define(string $categoryId, string $type, string $label, string $code, array $optionLabels = [], ?string $unit = null, ?int $min = null, ?int $max = null, bool $required = false): AttributeId
    {
        return ($this->service(DefineAttributeHandler::class))(
            new DefineAttribute($categoryId, $type, $label, $code, $required, $unit, $min, $max, $optionLabels),
        );
    }

    private function freshAttribute(AttributeId $id): Attribute
    {
        $this->em->clear();

        return $this->service(AttributeRepository::class)->get($id);
    }

    // ---------------------------------------------------------------- AC-12 define

    public function testDefiningAChoicePersistsOneAttributeRowAndOneRowPerOptionInInputOrder(): void
    {
        $category = CategoryFactory::createOne();

        $id = $this->define($category->id()->toString(), 'choice', 'Orientation', 'orientation', ['A', 'B', 'C'], required: true);

        self::assertSame(1, $this->rows('catalog_attribute'));
        self::assertSame(3, $this->rows('catalog_attribute_option'));
        self::assertSame($category->id()->toString(), $this->db->fetchOne('SELECT category_id FROM catalog_attribute WHERE id = ?', [$id->toString()]));
        $attribute = $this->freshAttribute($id);
        self::assertSame(AttributeType::Choice, $attribute->type());
        self::assertSame('Orientation', $attribute->label()->toString());
        self::assertSame('orientation', $attribute->code()->toString());
        self::assertTrue($attribute->isRequired());
        self::assertNull($attribute->numberRules());
        $options = (new DbalAttributeSchema($this->db))->optionsOf($id->toString());
        self::assertSame(['A', 'B', 'C'], array_column($options, 'label'));
    }

    public function testDefiningANumberPersistsItsRules(): void
    {
        $category = CategoryFactory::createOne();

        $id = $this->define($category->id()->toString(), 'number', 'Scale length', 'scale-length', unit: 'mm', min: 500, max: 700);

        self::assertSame(1, $this->rows('catalog_attribute'));
        self::assertSame(0, $this->rows('catalog_attribute_option'));
        $rules = $this->freshAttribute($id)->numberRules();
        self::assertNotNull($rules);
        self::assertSame('mm', $rules->unit()?->toString());
        self::assertSame(500, $rules->minimum());
        self::assertSame(700, $rules->maximum());
    }

    public function testDefiningABooleanPersistsOneRowWithNoRules(): void
    {
        $category = CategoryFactory::createOne();

        $id = $this->define($category->id()->toString(), 'boolean', 'Has case', 'has-case');

        self::assertSame(1, $this->rows('catalog_attribute'));
        self::assertSame(0, $this->rows('catalog_attribute_option'));
        $attribute = $this->freshAttribute($id);
        self::assertSame(AttributeType::Boolean, $attribute->type());
        self::assertNull($attribute->numberRules());
    }

    public function testDefiningATextPersistsOneRowWithNoRules(): void
    {
        $category = CategoryFactory::createOne();

        $id = $this->define($category->id()->toString(), 'text', 'Serial', 'serial');

        self::assertSame(1, $this->rows('catalog_attribute'));
        $attribute = $this->freshAttribute($id);
        self::assertSame(AttributeType::Text, $attribute->type());
        self::assertNull($attribute->numberRules());
    }

    // ---------------------------------------------------------------- AC-13 order by id

    public function testOptionIdsSortInExactInsertionOrderAcrossDefineAndLaterAdds(): void
    {
        $category = CategoryFactory::createOne();
        $labels = array_map(static fn (int $i): string => \sprintf('Option %02d', $i), range(1, 50));
        $id = $this->define($category->id()->toString(), 'choice', 'Brand', 'brand', $labels);

        $add = $this->service(AddAttributeOptionHandler::class);
        $add(new AddAttributeOption($id->toString(), 'Zebra'));
        $add(new AddAttributeOption($id->toString(), 'Apple'));

        self::assertSame([...$labels, 'Zebra', 'Apple'], $this->optionLabels($id->toString()));
        self::assertNotContains('position', $this->db->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'catalog_attribute_option'"));
    }

    // ---------------------------------------------------------------- AC-14 C-12

    public function testDefiningUnderAnUnknownCategoryIsRefusedAndWritesNothing(): void
    {
        $before = $this->snapshot();

        try {
            $this->define(Uuid::v7()->toRfc4122(), 'choice', 'Brand', 'brand', ['A']);
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testDefiningUnderAMalformedCategoryIdIsCategoryNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        $this->define('not-a-uuid', 'text', 'Serial', 'serial');
    }

    // ---------------------------------------------------------------- AC-15 C-11

    public function testASecondAttributeWithTheSameCodeInOneCategoryIsRefusedOnDefine(): void
    {
        $category = CategoryFactory::createOne();
        $this->define($category->id()->toString(), 'text', 'String count', 'string-count');
        $before = $this->snapshot();

        try {
            $this->define($category->id()->toString(), 'text', 'Another', 'string-count');
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testEditingToACodeAnotherAttributeOfTheCategoryHasIsRefused(): void
    {
        $category = CategoryFactory::createOne();
        $this->define($category->id()->toString(), 'text', 'String count', 'string-count');
        $other = $this->define($category->id()->toString(), 'text', 'Frets', 'frets');

        try {
            ($this->service(EditAttributeHandler::class))(new EditAttribute($other->toString(), 'Frets', 'string-count', false));
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken) {
            self::assertSame('frets', $this->db->fetchOne('SELECT code FROM catalog_attribute WHERE id = ?', [$other->toString()]));
        }
    }

    public function testTheSameCodeOnAnotherCategoryIsAccepted(): void
    {
        $first = CategoryFactory::createOne();
        $second = CategoryFactory::createOne();
        $this->define($first->id()->toString(), 'text', 'String count', 'string-count');

        $this->define($second->id()->toString(), 'text', 'String count', 'string-count');

        self::assertSame(2, $this->rows('catalog_attribute'));
    }

    public function testSavingAnAttributeWithItsOwnUnchangedCodeIsAccepted(): void
    {
        $category = CategoryFactory::createOne();
        $id = $this->define($category->id()->toString(), 'text', 'String count', 'string-count');

        ($this->service(EditAttributeHandler::class))(new EditAttribute($id->toString(), 'Strings', 'string-count', true));

        self::assertSame('Strings', $this->db->fetchOne('SELECT label FROM catalog_attribute WHERE id = ?', [$id->toString()]));
    }

    // ---------------------------------------------------------------- AC-17 C-17

    public function testAddingAnOptionThatDiffersOnlyByCaseIsRefused(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();
        $before = $this->snapshot();

        try {
            ($this->service(AddAttributeOptionHandler::class))(new AddAttributeOption($attribute->id()->toString(), 'RIGHT'));
            self::fail('Expected DuplicateAttributeOption.');
        } catch (DuplicateAttributeOption) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testRelabellingAnOptionToAnotherOptionsLabelIsRefused(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right', 'Left'])->create();
        $left = $this->optionId($attribute->id()->toString(), 'Left');
        $before = $this->snapshot();

        try {
            ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($attribute->id()->toString(), $left, 'right'));
            self::fail('Expected DuplicateAttributeOption.');
        } catch (DuplicateAttributeOption) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testRelabellingAnOptionToADifferentCaseOfItsOwnLabelIsAccepted(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();
        $right = $this->optionId($attribute->id()->toString(), 'Right');

        ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($attribute->id()->toString(), $right, 'RIGHT'));

        self::assertSame(['label' => 'RIGHT', 'label_key' => 'right'], $this->db->fetchAssociative('SELECT label, label_key FROM catalog_attribute_option WHERE id = ?', [$right]));
    }

    public function testTheSameLabelOnADifferentAttributeIsAccepted(): void
    {
        AttributeFactory::new()->choice(['Right'])->create();
        $other = AttributeFactory::new()->choice(['Left'])->create();

        ($this->service(AddAttributeOptionHandler::class))(new AddAttributeOption($other->id()->toString(), 'Right'));

        self::assertSame(['Left', 'Right'], $this->optionLabels($other->id()->toString()));
    }

    public function testRelabellingKeepsTheOptionId(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right', 'Left'])->create();
        $right = $this->optionId($attribute->id()->toString(), 'Right');

        ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($attribute->id()->toString(), $right, 'Dextral'));

        self::assertSame($right, $this->optionId($attribute->id()->toString(), 'Dextral'));
        self::assertSame(['Dextral', 'Left'], $this->optionLabels($attribute->id()->toString()));
    }

    // ---------------------------------------------------------------- AC-19 define is all-or-nothing

    public function testAnAttributeCodeCollidingAtTheIndexAfterTheOptionsWereWrittenLeavesNeitherTableChanged(): void
    {
        $category = CategoryFactory::createOne();
        $categoryId = $category->id()->toString();
        $before = $this->snapshot();
        $optionsWrittenWhenTheRaceHit = null;

        $em = $this->service(EntityManagerInterface::class);
        self::getContainer()->set(DoctrineAttributeRepository::class, new RacingAttributeRepository(
            new DoctrineAttributeRepository($em),
            function () use ($categoryId, &$optionsWrittenWhenTheRaceHit): void {
                $optionsWrittenWhenTheRaceHit = $this->rows('catalog_attribute_option');
                $this->db->executeStatement(
                    'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required) VALUES (?, ?, ?, ?, ?, false)',
                    [Uuid::v7()->toRfc4122(), $categoryId, 'brand', 'The other admin\'s Brand', 'text'],
                );
            },
        ));

        try {
            $this->define($categoryId, 'choice', 'Brand', 'brand', ['Fender', 'Gibson']);
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken) {
        }

        // The control: the options really had been written when the collision happened, so the
        // empty tables below are the rollback's doing and not a define that never got that far.
        self::assertSame(2, $optionsWrittenWhenTheRaceHit);
        self::assertSame($before, $this->snapshot());
    }

    // ---------------------------------------------------------------- AC-20 C-18

    public function testRemovingTheOnlyOptionIsRefusedAndTheRowRemains(): void
    {
        $attribute = AttributeFactory::new()->choice(['Only'])->create();
        $only = $this->optionId($attribute->id()->toString(), 'Only');

        try {
            ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($attribute->id()->toString(), $only));
            self::fail('Expected AttributeNeedsAnOption.');
        } catch (AttributeNeedsAnOption) {
            self::assertSame(['Only'], $this->optionLabels($attribute->id()->toString()));
        }
    }

    public function testRemovingOneOfTwoOptionsLeavesTheSurvivorUnchanged(): void
    {
        $attribute = AttributeFactory::new()->choice(['Keep', 'Drop'])->create();
        $keep = $this->db->fetchAssociative('SELECT * FROM catalog_attribute_option WHERE label = ?', ['Keep']);
        $drop = $this->optionId($attribute->id()->toString(), 'Drop');

        ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($attribute->id()->toString(), $drop));

        self::assertSame([$keep], $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option WHERE attribute_id = ?', [$attribute->id()->toString()]));
    }

    // ---------------------------------------------------------------- AC-21 option belongs to attribute

    public function testRelabellingAnotherAttributesOptionIsRefusedAndChangesNothing(): void
    {
        $mine = AttributeFactory::new()->choice(['Mine'])->create();
        $theirs = AttributeFactory::new()->choice(['Theirs'])->create();
        $foreign = $this->optionId($theirs->id()->toString(), 'Theirs');
        $before = $this->snapshot();

        try {
            ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($mine->id()->toString(), $foreign, 'Hijacked'));
            self::fail('Expected AttributeOptionNotFound.');
        } catch (AttributeOptionNotFound) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testRemovingAnotherAttributesOptionIsRefusedAndChangesNothing(): void
    {
        $mine = AttributeFactory::new()->choice(['Mine', 'Mine too'])->create();
        $theirs = AttributeFactory::new()->choice(['Theirs'])->create();
        $foreign = $this->optionId($theirs->id()->toString(), 'Theirs');
        $before = $this->snapshot();

        try {
            ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($mine->id()->toString(), $foreign));
            self::fail('Expected AttributeOptionNotFound.');
        } catch (AttributeOptionNotFound) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testAnUnknownOrMalformedOptionIdIsOptionNotFoundOnRelabelAndRemove(): void
    {
        $attribute = AttributeFactory::new()->choice(['A', 'B'])->create();
        $id = $attribute->id()->toString();
        $before = $this->snapshot();

        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $optionId) {
            try {
                ($this->service(RelabelAttributeOptionHandler::class))(new RelabelAttributeOption($id, $optionId, 'X'));
                self::fail('Expected AttributeOptionNotFound on relabel.');
            } catch (AttributeOptionNotFound) {
            }
            try {
                ($this->service(RemoveAttributeOptionHandler::class))(new RemoveAttributeOption($id, $optionId));
                self::fail('Expected AttributeOptionNotFound on remove.');
            } catch (AttributeOptionNotFound) {
            }
        }

        self::assertSame($before, $this->snapshot());
    }

    // ---------------------------------------------------------------- AC-22 edit

    public function testEditingANumberChangesLabelCodeRequiredAndRulesInOneCall(): void
    {
        $attribute = AttributeFactory::new()->number(NumberRules::of(Unit::fromString('mm'), 1, 2))->create();

        ($this->service(EditAttributeHandler::class))(new EditAttribute($attribute->id()->toString(), 'Nut width', 'nut-width', true, 'cm', 3, 9));

        $row = $this->db->fetchAssociative('SELECT label, code, is_required, number_rules FROM catalog_attribute WHERE id = ?', [$attribute->id()->toString()]);
        self::assertNotFalse($row);
        self::assertSame('Nut width', $row['label']);
        self::assertSame('nut-width', $row['code']);
        self::assertTrue($row['is_required']);
        self::assertIsString($row['number_rules']);
        // jsonb does not preserve key order, so compare as maps.
        self::assertEquals(['unit' => 'cm', 'min' => 3, 'max' => 9], json_decode($row['number_rules'], true));
    }

    public function testAnEditCombiningAValidRelabelWithMinimumAboveMaximumPersistsNothing(): void
    {
        $attribute = AttributeFactory::new()->labelled('Scale')->number(NumberRules::of(null, 1, 9))->create();
        $before = $this->snapshot();

        try {
            ($this->service(EditAttributeHandler::class))(new EditAttribute($attribute->id()->toString(), 'Renamed', $attribute->code()->toString(), false, null, 9, 1));
            self::fail('Expected InvalidNumberRules.');
        } catch (InvalidNumberRules) {
        }

        // A follow-up flush would persist a half-applied edit if the aggregate were still managed.
        $this->em->flush();
        self::assertSame($before, $this->snapshot());
        self::assertSame('Scale', $this->freshAttribute($attribute->id())->label()->toString());
    }

    // ---------------------------------------------------------------- AC-23 C-20

    public function testDeletingAChoiceRemovesItsAttributeRowAndAllItsOptionsAndNobodyElses(): void
    {
        $doomed = AttributeFactory::new()->choice(['A', 'B', 'C'])->create();
        $other = AttributeFactory::new()->choice(['X', 'Y'])->create();

        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($doomed->id()->toString()));

        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute WHERE id = ?', [$doomed->id()->toString()]));
        self::assertSame([], $this->optionLabels($doomed->id()->toString()));
        self::assertSame(['X', 'Y'], $this->optionLabels($other->id()->toString()));
        self::assertSame(1, $this->rows('catalog_attribute'));
    }

    public function testDeletingAnUnknownAttributeIsAttributeNotFound(): void
    {
        $this->expectException(AttributeNotFound::class);

        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute(Uuid::v7()->toRfc4122()));
    }

    // ---------------------------------------------------------------- AC-25 ids, not slugs

    public function testRenamingReslugAndMovingTheDefiningCategoryLeavesEveryAttributeAndOptionRowUntouched(): void
    {
        $guitars = CategoryFactory::createOne();
        $basses = CategoryFactory::createOne();
        $electric = CategoryFactory::new()->childOf($guitars)->create();
        $this->define($electric->id()->toString(), 'choice', 'Orientation', 'orientation', ['Left', 'Right']);
        $this->define($electric->id()->toString(), 'number', 'Frets', 'frets', unit: null, min: 1, max: 30);
        $before = $this->snapshot();

        ($this->service(EditCategoryHandler::class))(new EditCategory(
            $electric->id()->toString(),
            'Electric guitars, renamed',
            'electric-renamed',
            $basses->id()->toString(),
        ));

        self::assertSame($before, $this->snapshot());
        self::assertSame(
            $basses->id()->toString(),
            $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$electric->id()->toString()]),
            'Control: the move really happened.',
        );
        self::assertSame(CategoryId::fromString($electric->id()->toString())->toString(), $this->db->fetchOne('SELECT category_id FROM catalog_attribute WHERE code = ?', ['frets']));
    }
}
