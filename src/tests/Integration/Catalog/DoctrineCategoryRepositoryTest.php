<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\CorruptCategoryTree;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-12, AC-18, AC-19, AC-21: the adapter and the table behind it, against the real database.
 */
final class DoctrineCategoryRepositoryTest extends KernelTestCase
{
    private CategoryRepository $categories;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $categories = self::getContainer()->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $categories);
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->categories = $categories;
        $this->db = $db;
    }

    private function insertRaw(string $id, ?string $parentId, string $slug): void
    {
        $this->db->executeStatement(
            'INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, ?, ?, ?)',
            [$id, $parentId, $slug, ucfirst($slug)],
        );
    }

    private static function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * A failed statement aborts the surrounding transaction in PostgreSQL; DAMA's outer transaction
     * would then be unusable. Running it in a nested `transactional()` turns that into a savepoint
     * rollback, so the test can keep asserting afterwards.
     */
    private function assertUniqueViolation(\Closure $statement): void
    {
        try {
            $this->db->transactional(static function () use ($statement): null {
                $statement();

                return null;
            });
            self::fail('Expected a unique violation.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testRawDuplicateSiblingSlugViolatesTheUniqueIndex(): void
    {
        $parent = self::uuid();
        $this->insertRaw($parent, null, 'guitars');
        $this->insertRaw(self::uuid(), $parent, 'electric');

        $this->assertUniqueViolation(fn () => $this->insertRaw(self::uuid(), $parent, 'electric'));
    }

    public function testRawDuplicateRootSlugViolatesTheUniqueIndex(): void
    {
        $this->insertRaw(self::uuid(), null, 'guitars');

        $this->assertUniqueViolation(fn () => $this->insertRaw(self::uuid(), null, 'guitars'));
    }

    public function testSaveTranslatesAUniqueViolationOnASiblingSlugIntoCategorySlugAlreadyTaken(): void
    {
        $guitars = CategoryFactory::createOne();
        CategoryFactory::new(['slug' => CategorySlug::fromString('electric')])->childOf($guitars)->create();

        $duplicate = Category::create(
            $this->categories->nextIdentity(),
            CategoryName::fromString('Electric again'),
            CategorySlug::fromString('electric'),
            $this->categories->lineageOf($guitars->id()),
        );

        $this->expectException(CategorySlugAlreadyTaken::class);
        $this->expectExceptionMessage('"electric"');

        // The pre-check is bypassed on purpose: this is the backstop.
        $this->categories->save($duplicate);
    }

    public function testSaveTranslatesAUniqueViolationOnARootSlugIntoCategorySlugAlreadyTaken(): void
    {
        CategoryFactory::createOne(['slug' => CategorySlug::fromString('guitars')]);

        $duplicate = Category::create(
            $this->categories->nextIdentity(),
            CategoryName::fromString('Guitars again'),
            CategorySlug::fromString('guitars'),
            Lineage::ofRoot(),
        );

        $this->expectException(CategorySlugAlreadyTaken::class);

        $this->categories->save($duplicate);
    }

    public function testLineageOfARootIsItself(): void
    {
        $root = CategoryFactory::createOne();

        $lineage = $this->categories->lineageOf($root->id());

        self::assertSame(1, $lineage->depth());
        self::assertNotNull($lineage->last());
        self::assertTrue($lineage->last()->equals($root->id()));
    }

    public function testLineageRunsRootDownAndIncludesTheCategory(): void
    {
        $a = CategoryFactory::createOne();
        $b = CategoryFactory::new()->childOf($a)->create();
        $c = CategoryFactory::new()->childOf($b)->create();

        $lineage = $this->categories->lineageOf($c->id());

        self::assertSame(3, $lineage->depth());
        self::assertNotNull($lineage->last());
        self::assertTrue($lineage->last()->equals($c->id()));
        self::assertTrue($lineage->contains($a->id()));
    }

    public function testSubtreeHeightCountsTheCategoryItself(): void
    {
        $a = CategoryFactory::createOne();
        $b = CategoryFactory::new()->childOf($a)->create();
        CategoryFactory::new()->childOf($b)->create();

        self::assertSame(3, $this->categories->subtreeHeightOf($a->id()));
        self::assertSame(2, $this->categories->subtreeHeightOf($b->id()));
    }

    public function testIsSlugTakenAmongSiblingsExcludesTheCategoryItself(): void
    {
        $a = CategoryFactory::createOne(['slug' => CategorySlug::fromString('guitars')]);

        self::assertTrue($this->categories->isSlugTakenAmongSiblings(null, CategorySlug::fromString('guitars'), null));
        self::assertFalse($this->categories->isSlugTakenAmongSiblings(null, CategorySlug::fromString('guitars'), $a->id()));
        self::assertFalse($this->categories->isSlugTakenAmongSiblings($a->id(), CategorySlug::fromString('guitars'), null));
    }

    /**
     * C-4b. `statement_timeout` turns "the SQL does not bound the recursion" into a failing test
     * instead of a hung one: an unbounded `WITH RECURSIVE` over a cycle never terminates. `SET LOCAL`
     * lasts until DAMA rolls the test's transaction back.
     */
    private function plantCycle(): CategoryId
    {
        $this->db->executeStatement('SET LOCAL statement_timeout = 3000');
        [$a, $b] = [self::uuid(), self::uuid()];
        $this->insertRaw($a, null, 'a');
        $this->insertRaw($b, $a, 'b');
        $this->db->executeStatement('UPDATE catalog_category SET parent_id = ? WHERE id = ?', [$b, $a]);

        return CategoryId::fromString($a);
    }

    public function testLineageOfAPlantedCycleThrowsCorruptCategoryTree(): void
    {
        $a = $this->plantCycle();

        $this->expectException(CorruptCategoryTree::class);
        $this->expectExceptionMessage($a->toString());

        $this->categories->lineageOf($a);
    }

    public function testSubtreeHeightOfAPlantedCycleThrowsCorruptCategoryTree(): void
    {
        $a = $this->plantCycle();

        $this->expectException(CorruptCategoryTree::class);
        $this->expectExceptionMessage($a->toString());

        $this->categories->subtreeHeightOf($a);
    }

    public function testLineageOfAChainLongerThanTheWalkBoundThrowsCorruptCategoryTree(): void
    {
        $this->db->executeStatement('SET LOCAL statement_timeout = 3000');
        $parent = null;
        $leaf = '';
        for ($i = 0; $i < 7; ++$i) {
            $leaf = self::uuid();
            $this->insertRaw($leaf, $parent, 'level-'.$i);
            $parent = $leaf;
        }

        $this->expectException(CorruptCategoryTree::class);

        $this->categories->lineageOf(CategoryId::fromString($leaf));
    }

    public function testSubtreeHeightOfAChainLongerThanTheWalkBoundThrowsCorruptCategoryTree(): void
    {
        $this->db->executeStatement('SET LOCAL statement_timeout = 3000');
        $parent = null;
        $root = '';
        for ($i = 0; $i < 7; ++$i) {
            $id = self::uuid();
            $root = '' === $root ? $id : $root;
            $this->insertRaw($id, $parent, 'level-'.$i);
            $parent = $id;
        }

        $this->expectException(CorruptCategoryTree::class);

        $this->categories->subtreeHeightOf(CategoryId::fromString($root));
    }

    public function testTheTableHasTheSpecifiedColumnsAndNoForeignKey(): void
    {
        $columns = $this->db->fetchAllKeyValue(
            "SELECT column_name, data_type || ':' || coalesce(character_maximum_length::text, '') || ':' || is_nullable
             FROM information_schema.columns WHERE table_name = 'catalog_category'",
        );

        self::assertSame([
            'id' => 'uuid::NO',
            'parent_id' => 'uuid::YES',
            'slug' => 'character varying:64:NO',
            'name' => 'character varying:100:NO',
        ], $columns);
        $foreignKeys = $this->db->fetchOne("SELECT count(*) FROM pg_constraint WHERE conrelid = 'catalog_category'::regclass AND contype = 'f'");
        self::assertSame(0, $foreignKeys);
    }

    public function testBothPartialUniqueIndexesExistWithTheirPredicates(): void
    {
        /** @var array<string, string> $indexes */
        $indexes = $this->db->fetchAllKeyValue("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'catalog_category'");

        self::assertArrayHasKey('uniq_catalog_category_sibling_slug', $indexes);
        self::assertStringContainsString('UNIQUE', $indexes['uniq_catalog_category_sibling_slug']);
        self::assertStringContainsString('(parent_id, slug)', $indexes['uniq_catalog_category_sibling_slug']);
        self::assertStringContainsString('WHERE (parent_id IS NOT NULL)', $indexes['uniq_catalog_category_sibling_slug']);

        self::assertArrayHasKey('uniq_catalog_category_root_slug', $indexes);
        self::assertStringContainsString('UNIQUE', $indexes['uniq_catalog_category_root_slug']);
        self::assertStringContainsString('(slug)', $indexes['uniq_catalog_category_root_slug']);
        self::assertStringContainsString('WHERE (parent_id IS NULL)', $indexes['uniq_catalog_category_root_slug']);
    }

    public function testNextIdentityMintsAUuidV7(): void
    {
        $id = $this->categories->nextIdentity();

        self::assertSame('7', $id->toString()[14]);
        self::assertFalse($id->equals($this->categories->nextIdentity()));
    }

    public function testTheCategoryDbalTypesAreRegistered(): void
    {
        foreach (['catalog_category_id', 'catalog_category_name', 'catalog_category_slug'] as $name) {
            self::assertTrue(Type::hasType($name), $name);
        }
    }
}
