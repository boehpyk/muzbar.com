<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\CategoryMoveWouldDuplicateAttributeCode;
use App\Domain\Catalog\Exception\CorruptCategoryTree;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-8 … AC-20 of `catalog-schema-inheritance`, against the real `muzbar_test` database (DAMA).
 *
 * The fixture tree of the spec:
 *
 *     Instruments   defines condition ("Condition", choice)
 *     ├── Guitars   defines strings   ("String count", number)
 *     │   └── Electric  defines pickups ("Pickups", choice)
 *     │       └── Seven     defines scale ("Scale length", number)
 *     └── Bass      defines nothing
 *
 * `AttributeFactory` bypasses C-21 by design, so every attribute here is lawful on its own branch
 * and any deliberately unlawful row is planted with raw SQL. "Unchanged" means whole tables compared
 * before and after; read-backs go through DBAL or a repository fetched after `EntityManager::clear()`.
 */
final class SchemaInheritanceTest extends KernelTestCase
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
     * @return array{instruments: Category, guitars: Category, electric: Category, seven: Category, bass: Category, condition: Attribute, strings: Attribute, pickups: Attribute, scale: Attribute}
     */
    private function tree(): array
    {
        $instruments = CategoryFactory::createOne(['name' => CategoryName::fromString('Instruments')]);
        $guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars')])->childOf($instruments)->create();
        $electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric')])->childOf($guitars)->create();
        $seven = CategoryFactory::new(['name' => CategoryName::fromString('Seven')])->childOf($electric)->create();
        $bass = CategoryFactory::new(['name' => CategoryName::fromString('Bass')])->childOf($instruments)->create();

        return [
            'instruments' => $instruments,
            'guitars' => $guitars,
            'electric' => $electric,
            'seven' => $seven,
            'bass' => $bass,
            'condition' => AttributeFactory::new()->inCategory($instruments)->coded('condition')->labelled('Condition')->choice(['New', 'Used'])->create(),
            'strings' => AttributeFactory::new()->inCategory($guitars)->coded('strings')->labelled('String count')->number(NumberRules::of(null, 4, 12))->create(),
            'pickups' => AttributeFactory::new()->inCategory($electric)->coded('pickups')->labelled('Pickups')->choice(['Single', 'Humbucker'])->create(),
            'scale' => AttributeFactory::new()->inCategory($seven)->coded('scale')->labelled('Scale length')->number(NumberRules::of(null, 600, 700))->create(),
        ];
    }

    /**
     * @return list<string>
     */
    private function codesOf(Category $category): array
    {
        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($category->id()->toString());

        return array_column($rows, 'code');
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function attributeTables(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute ORDER BY id'),
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option ORDER BY id'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryTable(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM catalog_category ORDER BY id');
    }

    private function define(Category $category, string $code, string $type = 'text', string $label = 'Anything'): AttributeId
    {
        return ($this->service(DefineAttributeHandler::class))(
            new DefineAttribute($category->id()->toString(), $type, $label, $code, false, null, null, null, 'choice' === $type ? ['Only'] : []),
        );
    }

    private function edit(Attribute $attribute, string $code, ?string $label = null): void
    {
        ($this->service(EditAttributeHandler::class))(new EditAttribute(
            $attribute->id()->toString(),
            $label ?? $attribute->label()->toString(),
            $code,
            $attribute->isRequired(),
        ));
    }

    private function move(Category $category, Category $under): void
    {
        ($this->service(EditCategoryHandler::class))(new EditCategory(
            $category->id()->toString(),
            $category->name()->toString(),
            $category->slug()->toString(),
            $under->id()->toString(),
        ));
    }

    private function insertRawAttribute(Category $category, string $code): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->db->executeStatement(
            'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required, number_rules) VALUES (?, ?, ?, ?, ?, false, NULL)',
            [$id, $category->id()->toString(), $code, ucfirst($code), 'text'],
        );

        return $id;
    }

    // ---------------------------------------------------------------- AC-8 effective schema

    public function testTheEffectiveSchemaOfADeepCategoryListsEveryLineageAttributeRootFirst(): void
    {
        $t = $this->tree();

        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($t['seven']->id()->toString());

        self::assertSame(['condition', 'strings', 'pickups', 'scale'], array_column($rows, 'code'));
        self::assertSame([true, true, true, false], array_column($rows, 'inherited'));
        self::assertSame(
            [$t['instruments']->id()->toString(), $t['guitars']->id()->toString(), $t['electric']->id()->toString(), $t['seven']->id()->toString()],
            array_column($rows, 'definedOnId'),
        );
        self::assertSame(['Instruments', 'Guitars', 'Electric', 'Seven'], array_column($rows, 'definedOnName'));
    }

    public function testTheEffectiveSchemaOfOtherCategories(): void
    {
        $t = $this->tree();

        self::assertSame(['condition', 'strings'], $this->codesOf($t['guitars']));
        self::assertSame(['condition'], $this->codesOf($t['bass']));

        $root = (new DbalAttributeSchema($this->db))->effectiveFor($t['instruments']->id()->toString());
        self::assertSame(['condition'], array_column($root, 'code'));
        self::assertSame([false], array_column($root, 'inherited'));
    }

    public function testTheEffectiveSchemaOfAnUnknownCategoryIsEmpty(): void
    {
        $this->tree();

        self::assertSame([], (new DbalAttributeSchema($this->db))->effectiveFor(Uuid::v7()->toRfc4122()));
    }

    public function testWithinOneLevelTheEffectiveSchemaOrdersByLabelCaseInsensitively(): void
    {
        $t = $this->tree();
        AttributeFactory::new()->inCategory($t['instruments'])->coded('aaa')->labelled('apple')->create();
        AttributeFactory::new()->inCategory($t['instruments'])->coded('zzz')->labelled('Zebra')->create();

        self::assertSame(['aaa', 'condition', 'zzz'], $this->codesOf($t['instruments']));
    }

    // ---------------------------------------------------------------- AC-9 / AC-10 define

    public function testDefiningACodeAnAncestorHasIsRefusedAndWritesNothing(): void
    {
        $t = $this->tree();
        $before = $this->attributeTables();

        try {
            $this->define($t['electric'], 'strings');
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken $e) {
            self::assertSame('strings', $e->code()->toString());
            self::assertSame($before, $this->attributeTables());
        }
    }

    public function testDefiningACodeADescendantHasIsRefusedOnTheParentAndOnTheRoot(): void
    {
        $t = $this->tree();
        $before = $this->attributeTables();

        foreach ([[$t['guitars'], 'pickups'], [$t['instruments'], 'scale']] as [$category, $code]) {
            try {
                $this->define($category, $code);
                self::fail('Expected AttributeCodeAlreadyTaken for '.$code);
            } catch (AttributeCodeAlreadyTaken $e) {
                self::assertSame($code, $e->code()->toString());
                self::assertSame($before, $this->attributeTables(), $code);
            }
        }
    }

    public function testDefiningACodeASiblingBranchHasSucceedsAndEachBranchSeesItOnce(): void
    {
        $t = $this->tree();

        $this->define($t['bass'], 'pickups');

        self::assertSame(['condition', 'pickups'], $this->codesOf($t['bass']));
        self::assertCount(1, array_keys($this->codesOf($t['electric']), 'pickups', true));
    }

    public function testASecondAttributeWithTheSameCodeOnTheSameCategoryIsStillRefused(): void
    {
        $t = $this->tree();
        $before = $this->attributeTables();

        try {
            $this->define($t['instruments'], 'condition');
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken) {
            self::assertSame($before, $this->attributeTables());
        }
    }

    // ---------------------------------------------------------------- AC-13 recode

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesInForceOnPickupsBranch(): iterable
    {
        yield 'an ancestor\'s' => ['strings'];
        yield 'a descendant\'s' => ['scale'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codesInForceOnPickupsBranch')]
    public function testRecodingToACodeInForceOnTheBranchIsRefusedAndTheCodeStays(string $code): void
    {
        $t = $this->tree();

        try {
            $this->edit($t['pickups'], $code);
            self::fail('Expected AttributeCodeAlreadyTaken.');
        } catch (AttributeCodeAlreadyTaken $e) {
            self::assertSame($code, $e->code()->toString());
        }

        $this->em->clear();
        $fresh = $this->service(AttributeRepository::class)->get($t['pickups']->id());
        self::assertSame('pickups', $fresh->code()->toString());
    }

    public function testRecodingToAFreeCodeSucceeds(): void
    {
        $t = $this->tree();

        $this->edit($t['pickups'], 'pickup-count');

        $this->em->clear();
        self::assertSame('pickup-count', $this->service(AttributeRepository::class)->get($t['pickups']->id())->code()->toString());
    }

    public function testSavingWithItsOwnUnchangedCodeSucceeds(): void
    {
        $t = $this->tree();

        $this->edit($t['pickups'], 'pickups', 'Pickup layout');

        $this->em->clear();
        self::assertSame('Pickup layout', $this->service(AttributeRepository::class)->get($t['pickups']->id())->label()->toString());
    }

    // ---------------------------------------------------------------- AC-14 / AC-15 / AC-16 move

    public function testMovingUnderAParentWhoseLineageHasTheMovingCategorysCodeIsRefused(): void
    {
        $t = $this->tree();
        AttributeFactory::new()->inCategory($t['bass'])->coded('strings')->create();
        $before = $this->categoryTable();

        try {
            $this->move($t['bass'], $t['guitars']);
            self::fail('Expected CategoryMoveWouldDuplicateAttributeCode.');
        } catch (CategoryMoveWouldDuplicateAttributeCode $e) {
            self::assertSame('strings', $e->code()->toString());
            self::assertSame($before, $this->categoryTable());
        }
    }

    public function testADescendantsCodeCountsWhenMoving(): void
    {
        $t = $this->tree();
        AttributeFactory::new()->inCategory($t['bass'])->coded('scale')->create();
        $before = $this->categoryTable();

        try {
            // Seven, which defines `scale`, is two levels below Electric.
            $this->move($t['electric'], $t['bass']);
            self::fail('Expected CategoryMoveWouldDuplicateAttributeCode.');
        } catch (CategoryMoveWouldDuplicateAttributeCode $e) {
            self::assertSame('scale', $e->code()->toString());
            self::assertSame($before, $this->categoryTable());
        }
    }

    public function testAMoveWithDisjointCodesSucceedsAndTheEffectiveSchemaFollows(): void
    {
        $t = $this->tree();
        AttributeFactory::new()->inCategory($t['bass'])->coded('pickups')->labelled('Bass pickups')->create();

        $this->move($t['seven'], $t['bass']);

        self::assertSame(['condition', 'pickups', 'scale'], $this->codesOf($t['seven']));
        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($t['seven']->id()->toString());
        self::assertSame('Bass', $rows[1]['definedOnName']);
    }

    public function testTwoSiblingLeavesThatBothDefineACodeMoveTogetherUnderAParentWithoutIt(): void
    {
        $subtree = CategoryFactory::createOne();
        foreach ([1, 2] as $_) {
            $leaf = CategoryFactory::new()->childOf($subtree)->create();
            AttributeFactory::new()->inCategory($leaf)->coded('fx')->create();
        }
        $parent = CategoryFactory::createOne();

        $this->move($subtree, $parent);

        $this->em->clear();
        $moved = $this->service(CategoryRepository::class)->get($subtree->id());
        self::assertTrue($moved->parentId()?->equals($parent->id()));
    }

    public function testASuccessfulMoveWritesExactlyOneCategoryRowAndNoAttributeOrOptionRow(): void
    {
        $t = $this->tree();
        AttributeFactory::new()->inCategory($t['bass'])->coded('pickups')->create();
        $attributesBefore = $this->attributeTables();
        $categoriesBefore = $this->categoryTable();

        $this->move($t['seven'], $t['bass']);

        self::assertSame($attributesBefore, $this->attributeTables());
        $changed = array_values(array_filter(
            $this->categoryTable(),
            static fn (array $row): bool => !\in_array($row, $categoriesBefore, true),
        ));
        self::assertCount(1, $changed);
        self::assertSame($t['seven']->id()->toString(), $changed[0]['id']);
        self::assertSame($t['bass']->id()->toString(), $changed[0]['parent_id']);
        $original = array_values(array_filter($categoriesBefore, static fn (array $row): bool => $row['id'] === $changed[0]['id']))[0];
        self::assertSame(
            array_diff_key($original, ['parent_id' => 1]),
            array_diff_key($changed[0], ['parent_id' => 1]),
            'Only parent_id may differ.',
        );
    }

    // ---------------------------------------------------------------- AC-17 / AC-18 derivation

    public function testDefiningOnTheRootAddsOneRowAndEveryDescendantSeesIt(): void
    {
        $t = $this->tree();
        $before = $this->attributeTables()[0];

        $this->define($t['instruments'], 'finish');

        self::assertCount(\count($before) + 1, $this->attributeTables()[0]);
        self::assertContains('finish', $this->codesOf($t['seven']));
        self::assertContains('finish', $this->codesOf($t['bass']));
    }

    public function testRelabellingAParentsAttributeChangesOneRowAndDescendantsShowTheNewLabel(): void
    {
        $t = $this->tree();
        $before = $this->attributeTables();

        $this->edit($t['condition'], 'condition', 'State');

        $after = $this->attributeTables();
        self::assertSame($before[1], $after[1]);
        self::assertCount(1, array_filter($after[0], static fn (array $row): bool => !\in_array($row, $before[0], true)));
        $labels = array_column((new DbalAttributeSchema($this->db))->effectiveFor($t['seven']->id()->toString()), 'label');
        self::assertContains('State', $labels);
        self::assertNotContains('Condition', $labels);
    }

    public function testDeletingAParentsAttributeRemovesItFromEveryCategoryBelow(): void
    {
        $t = $this->tree();

        ($this->service(DeleteAttributeHandler::class))(new DeleteAttribute($t['condition']->id()->toString()));

        foreach (['instruments', 'guitars', 'electric', 'seven', 'bass'] as $key) {
            self::assertNotContains('condition', $this->codesOf($t[$key]), $key);
        }
    }

    public function testCreatingACategoryWritesOneRowAndInheritsTheLineageSchema(): void
    {
        $t = $this->tree();
        $attributesBefore = $this->attributeTables();
        $categoriesBefore = \count($this->categoryTable());

        $id = ($this->service(CreateCategoryHandler::class))(new CreateCategory('Dobro', 'dobro', $t['electric']->id()->toString()));

        self::assertCount($categoriesBefore + 1, $this->categoryTable());
        self::assertSame($attributesBefore, $this->attributeTables());
        $rows = (new DbalAttributeSchema($this->db))->effectiveFor($id->toString());
        self::assertSame(['condition', 'strings', 'pickups'], array_column($rows, 'code'));
    }

    // ---------------------------------------------------------------- AC-19 fact reads

    /**
     * @param list<CategoryId> $ids
     *
     * @return list<string>
     */
    private static function sortedStrings(array $ids): array
    {
        $strings = array_map(static fn (CategoryId $id): string => $id->toString(), $ids);
        sort($strings);

        return $strings;
    }

    public function testDescendantsOfExcludesTheCategoryItselfAndReachesEveryLevel(): void
    {
        $t = $this->tree();
        $categories = $this->service(CategoryRepository::class);

        self::assertSame([$t['seven']->id()->toString()], self::sortedStrings($categories->descendantsOf($t['electric']->id())));

        $expected = array_map(static fn (Category $c): string => $c->id()->toString(), [$t['guitars'], $t['electric'], $t['seven'], $t['bass']]);
        sort($expected);
        self::assertSame($expected, self::sortedStrings($categories->descendantsOf($t['instruments']->id())));

        self::assertSame([], $categories->descendantsOf($t['seven']->id()));
    }

    public function testDescendantsOfAPlantedCycleThrowsCorruptCategoryTreeInsteadOfLooping(): void
    {
        $this->db->executeStatement('SET LOCAL statement_timeout = 3000');
        [$a, $b] = [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()];
        $this->db->executeStatement('INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, NULL, ?, ?)', [$a, 'a', 'A']);
        $this->db->executeStatement('INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, ?, ?, ?)', [$b, $a, 'b', 'B']);
        $this->db->executeStatement('UPDATE catalog_category SET parent_id = ? WHERE id = ?', [$b, $a]);

        $this->expectException(CorruptCategoryTree::class);

        $this->service(CategoryRepository::class)->descendantsOf(CategoryId::fromString($a));
    }

    public function testCodesDefinedOnReturnsTheCodesOfExactlyTheGivenCategories(): void
    {
        $t = $this->tree();

        $codes = $this->service(AttributeRepository::class)->codesDefinedOn([$t['instruments']->id(), $t['electric']->id()]);

        self::assertSame(
            ['condition', 'pickups'],
            array_map(static fn (AttributeCode $c): string => $c->toString(), $codes->toList()),
        );
    }

    public function testCodesDefinedOnNoCategoriesIsEmptyAndIssuesNoQuery(): void
    {
        $this->tree();
        $attributes = $this->service(AttributeRepository::class);
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $codes = $attributes->codesDefinedOn([]);
            $recorded = QueryRecordingStatement::uninstall($pdo);
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }

        self::assertSame([], $codes->toList());
        self::assertSame([], $recorded);
    }

    public function testCodesDefinedOnNonEmptyInputDoesIssueAQueryThatTheRecorderSees(): void
    {
        $t = $this->tree();
        $attributes = $this->service(AttributeRepository::class);
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $attributes->codesDefinedOn([$t['instruments']->id()]);
        } finally {
            $recorded = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertNotSame([], $recorded, 'The recorder is blind, so the empty-input test above proves nothing.');
    }

    // ---------------------------------------------------------------- AC-20 legacy duplicates

    public function testAPlantedDuplicateIsListedTwiceWithoutError(): void
    {
        $t = $this->tree();
        $this->insertRawAttribute($t['electric'], 'strings');

        self::assertSame(['strings', 'strings'], array_values(array_filter($this->codesOf($t['electric']), static fn (string $c): bool => 'strings' === $c)));
    }

    public function testAPlantedDuplicateCanBeRecodedToAFreeCode(): void
    {
        $t = $this->tree();
        $planted = $this->insertRawAttribute($t['electric'], 'strings');

        ($this->service(EditAttributeHandler::class))(new EditAttribute($planted, 'Strings', 'string-gauge', false));

        self::assertSame('string-gauge', $this->db->fetchOne('SELECT code FROM catalog_attribute WHERE id = ?', [$planted]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codesStillInForce(): iterable
    {
        yield 'an ancestor\'s' => ['condition'];
        yield 'a descendant\'s' => ['scale'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codesStillInForce')]
    public function testAPlantedDuplicateCannotBeRecodedToACodeStillInForce(string $code): void
    {
        $t = $this->tree();
        $planted = $this->insertRawAttribute($t['electric'], 'strings');

        $this->expectException(AttributeCodeAlreadyTaken::class);

        ($this->service(EditAttributeHandler::class))(new EditAttribute($planted, 'Strings', $code, false));
    }
}
