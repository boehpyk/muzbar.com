<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DeleteCategory;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DeleteCategoryHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryHasChildren;
use App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\CategoryTreeTooDeep;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-10 … AC-16: the three Catalog handlers against the real `muzbar_test` database (DAMA
 * rollback). Rows are read back through DBAL, never through a repository that may be serving
 * Doctrine's identity map; "unchanged" means the whole table compared before and after.
 */
final class CategoryHandlersTest extends KernelTestCase
{
    private CreateCategoryHandler $create;
    private EditCategoryHandler $edit;
    private DeleteCategoryHandler $delete;
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $repository = $container->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $repository);
        $lock = $container->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $this->create = new CreateCategoryHandler($repository, $lock);
        $this->edit = new EditCategoryHandler($repository, $lock);
        $this->delete = new DeleteCategoryHandler($repository, $lock);
        $this->db = $db;
        $this->em = $em;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, parent_id, slug, name FROM catalog_category ORDER BY id');
    }

    private function countWhere(string $where, string $value): int
    {
        $count = $this->db->fetchOne('SELECT count(*) FROM catalog_category WHERE '.$where, [$value]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function parentOf(Category $category): ?string
    {
        $parent = $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$category->id()->toString()]);
        self::assertTrue(null === $parent || \is_string($parent), 'Row missing.');

        return $parent;
    }

    private function nameOf(Category $category): string
    {
        $name = $this->db->fetchOne('SELECT name FROM catalog_category WHERE id = ?', [$category->id()->toString()]);
        self::assertIsString($name);

        return $name;
    }

    private function edit(Category $category, ?string $name = null, ?string $slug = null, ?Category $parent = null, bool $keepParent = true): void
    {
        ($this->edit)(new EditCategory(
            $category->id()->toString(),
            $name ?? $category->name()->toString(),
            $slug ?? $category->slug()->toString(),
            $keepParent ? $category->parentId()?->toString() : $parent?->id()->toString(),
        ));
    }

    /**
     * @return array{guitars: Category, electric: Category, solidBody: Category}
     */
    private function guitarsTree(): array
    {
        $guitars = CategoryFactory::createOne(['name' => CategoryName::fromString('Guitars'), 'slug' => CategorySlug::fromString('guitars')]);
        $electric = CategoryFactory::new(['slug' => CategorySlug::fromString('electric')])->childOf($guitars)->create();
        $solidBody = CategoryFactory::new(['slug' => CategorySlug::fromString('solid-body')])->childOf($electric)->create();

        return ['guitars' => $guitars, 'electric' => $electric, 'solidBody' => $solidBody];
    }

    public function testCreatingARootPersistsOneRowWithNoParent(): void
    {
        $id = ($this->create)(new CreateCategory('Guitars', 'guitars', null));

        $rows = $this->table();
        self::assertCount(1, $rows);
        self::assertSame($id->toString(), $rows[0]['id']);
        self::assertNull($rows[0]['parent_id']);
        self::assertSame('guitars', $rows[0]['slug']);
        self::assertSame('Guitars', $rows[0]['name']);
    }

    public function testACreatedChildPointsAtItsParent(): void
    {
        $guitars = ($this->create)(new CreateCategory('Guitars', 'guitars', null));

        $child = ($this->create)(new CreateCategory('Electric', 'electric', $guitars->toString()));

        self::assertSame(
            $guitars->toString(),
            $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$child->toString()]),
        );
    }

    public function testACategoryAtDepthFiveIsRefusedAndNothingIsWritten(): void
    {
        $a = ($this->create)(new CreateCategory('A', 'a', null));
        $b = ($this->create)(new CreateCategory('B', 'b', $a->toString()));
        $c = ($this->create)(new CreateCategory('C', 'c', $b->toString()));
        $d = ($this->create)(new CreateCategory('D', 'd', $c->toString()));
        $before = $this->table();

        try {
            ($this->create)(new CreateCategory('E', 'e', $d->toString()));
            self::fail('Expected CategoryTreeTooDeep.');
        } catch (CategoryTreeTooDeep) {
            self::assertSame($before, $this->table());
            self::assertCount(4, $before);
        }
    }

    public function testASecondRootWithTheSameSlugIsRefused(): void
    {
        ($this->create)(new CreateCategory('Guitars', 'guitars', null));

        $this->expectException(CategorySlugAlreadyTaken::class);

        ($this->create)(new CreateCategory('Other Guitars', 'guitars', null));
    }

    public function testASecondChildWithTheSameSlugUnderTheSameParentIsRefused(): void
    {
        $guitars = ($this->create)(new CreateCategory('Guitars', 'guitars', null));
        ($this->create)(new CreateCategory('Electric', 'electric', $guitars->toString()));

        $this->expectException(CategorySlugAlreadyTaken::class);

        ($this->create)(new CreateCategory('Electric 2', 'electric', $guitars->toString()));
    }

    public function testTheSameSlugUnderADifferentParentIsAccepted(): void
    {
        $guitars = ($this->create)(new CreateCategory('Guitars', 'guitars', null));
        $basses = ($this->create)(new CreateCategory('Basses', 'basses', null));
        ($this->create)(new CreateCategory('Electric', 'electric', $guitars->toString()));

        ($this->create)(new CreateCategory('Electric', 'electric', $basses->toString()));

        self::assertSame(2, $this->countWhere('slug = ?', 'electric'));
    }

    public function testChangingASlugToOneAlreadyTakenAmongSiblingsIsRefused(): void
    {
        $tree = $this->guitarsTree();
        $acoustic = CategoryFactory::new(['slug' => CategorySlug::fromString('acoustic')])->childOf($tree['guitars'])->create();
        $before = $this->table();

        try {
            $this->edit($acoustic, slug: 'electric');
            self::fail('Expected CategorySlugAlreadyTaken.');
        } catch (CategorySlugAlreadyTaken) {
            self::assertSame($before, $this->table());
        }
    }

    public function testKeepingOwnSlugIsNotACollisionWithItself(): void
    {
        $tree = $this->guitarsTree();

        $this->edit($tree['electric'], name: 'Electric Guitars');

        self::assertSame('Electric Guitars', $this->nameOf($tree['electric']));
    }

    public function testMovingIntoAParentThatAlreadyHasThatSlugIsRefused(): void
    {
        $tree = $this->guitarsTree();
        $basses = CategoryFactory::createOne();
        CategoryFactory::new(['slug' => CategorySlug::fromString('electric')])->childOf($basses)->create();
        $before = $this->table();

        try {
            $this->edit($tree['electric'], parent: $basses, keepParent: false);
            self::fail('Expected CategorySlugAlreadyTaken.');
        } catch (CategorySlugAlreadyTaken) {
            self::assertSame($before, $this->table());
        }
    }

    public function testMovingARootIntoTheRootLevelCollidesOnSlug(): void
    {
        $tree = $this->guitarsTree();
        CategoryFactory::new(['slug' => CategorySlug::fromString('electric')])->create();

        $this->expectException(CategorySlugAlreadyTaken::class);

        $this->edit($tree['electric'], parent: null, keepParent: false);
    }

    public function testMovingAnAncestorUnderItsDescendantIsRefusedAsACycle(): void
    {
        $tree = $this->guitarsTree();
        $before = $this->table();

        try {
            $this->edit($tree['guitars'], parent: $tree['solidBody'], keepParent: false);
            self::fail('Expected CategoryMoveWouldCreateCycle.');
        } catch (CategoryMoveWouldCreateCycle) {
            self::assertSame($before, $this->table());
        }
    }

    public function testMovingACategoryUnderItselfIsRefusedAsACycle(): void
    {
        $tree = $this->guitarsTree();

        $this->expectException(CategoryMoveWouldCreateCycle::class);

        $this->edit($tree['electric'], parent: $tree['electric'], keepParent: false);
    }

    public function testMovingASubtreeBeyondTheDepthCeilingIsRefused(): void
    {
        $tree = $this->guitarsTree();
        $basses = CategoryFactory::createOne();
        $fiveString = CategoryFactory::new()->childOf($basses)->create();
        $fretless = CategoryFactory::new()->childOf($fiveString)->create();
        $before = $this->table();

        // `electric` has height 2 (itself + solid-body); under a depth-3 node it would reach level 5.
        try {
            $this->edit($tree['electric'], parent: $fretless, keepParent: false);
            self::fail('Expected CategoryTreeTooDeep.');
        } catch (CategoryTreeTooDeep) {
            self::assertSame($before, $this->table());
        }
    }

    public function testMovingToTheRootUpdatesExactlyOneRow(): void
    {
        $tree = $this->guitarsTree();
        $before = $this->table();

        $this->edit($tree['electric'], parent: null, keepParent: false);

        $after = $this->table();
        self::assertNull($this->parentOf($tree['electric']));
        self::assertSame($tree['electric']->id()->toString(), $this->db->fetchOne('SELECT parent_id FROM catalog_category WHERE id = ?', [$tree['solidBody']->id()->toString()]));
        $changed = array_filter($after, static fn (array $row, int $i): bool => $row !== $before[$i], \ARRAY_FILTER_USE_BOTH);
        self::assertCount(1, $changed, 'Only the moved row may differ.');
    }

    public function testCreatingUnderAnUnknownParentIsRefusedAndNothingIsWritten(): void
    {
        try {
            ($this->create)(new CreateCategory('X', 'x', '018f5b2a-0000-7000-8000-000000000000'));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame([], $this->table());
        }
    }

    public function testCreatingUnderAMalformedParentIdIsCategoryNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        ($this->create)(new CreateCategory('X', 'x', 'not-a-uuid'));
    }

    public function testEditingToAnUnknownParentIsRefusedAndNothingIsWritten(): void
    {
        $tree = $this->guitarsTree();
        $before = $this->table();

        try {
            ($this->edit)(new EditCategory($tree['electric']->id()->toString(), 'Renamed', 'electric', '018f5b2a-0000-7000-8000-000000000000'));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame($before, $this->table());
        }
    }

    public function testEditingToAMalformedParentIdIsCategoryNotFound(): void
    {
        $tree = $this->guitarsTree();

        $this->expectException(CategoryNotFound::class);

        ($this->edit)(new EditCategory($tree['electric']->id()->toString(), 'Electric', 'electric', 'nope'));
    }

    public function testEditingAnUnknownCategoryIsCategoryNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        ($this->edit)(new EditCategory('018f5b2a-0000-7000-8000-000000000000', 'X', 'x', null));
    }

    public function testDeletingAnUnknownCategoryIsCategoryNotFound(): void
    {
        $this->expectException(CategoryNotFound::class);

        ($this->delete)(new DeleteCategory('018f5b2a-0000-7000-8000-000000000000'));
    }

    public function testDeletingALeafRemovesItsRow(): void
    {
        $tree = $this->guitarsTree();

        ($this->delete)(new DeleteCategory($tree['solidBody']->id()->toString()));

        self::assertSame(0, $this->countWhere('id = ?', $tree['solidBody']->id()->toString()));
        self::assertCount(2, $this->table());
    }

    public function testDeletingACategoryWithAChildIsRefusedAndBothRowsRemain(): void
    {
        $tree = $this->guitarsTree();
        $before = $this->table();

        try {
            ($this->delete)(new DeleteCategory($tree['electric']->id()->toString()));
            self::fail('Expected CategoryHasChildren.');
        } catch (CategoryHasChildren) {
            self::assertSame($before, $this->table());
        }
    }

    public function testAFailedEditThatHadAlreadyRenamedPersistsNothingEvenAfterAFollowUpFlush(): void
    {
        $tree = $this->guitarsTree();
        $acoustic = CategoryFactory::new(['slug' => CategorySlug::fromString('acoustic')])->childOf($tree['guitars'])->create();
        $originalName = $this->nameOf($acoustic);

        // The rename is applied to the managed aggregate *before* the sibling check refuses the slug,
        // so the EntityManager holds a dirty entity when the handler throws.
        try {
            $this->edit($acoustic, name: 'Half Applied', slug: 'electric');
            self::fail('Expected CategorySlugAlreadyTaken.');
        } catch (CategorySlugAlreadyTaken) {
        }

        $this->em->flush();

        self::assertSame($originalName, $this->nameOf($acoustic));
        self::assertSame('acoustic', $this->db->fetchOne('SELECT slug FROM catalog_category WHERE id = ?', [$acoustic->id()->toString()]));
    }

    /**
     * The handler refuses the move before it renames, so nothing is dirty when it throws. This
     * proves the order, not the lock adapter's clear(); for that see
     * testAFailedEditThatHadAlreadyRenamedPersistsNothingEvenAfterAFollowUpFlush.
     */
    public function testACycleIsRefusedBeforeTheRenameSoNeitherChangePersists(): void
    {
        $tree = $this->guitarsTree();
        $originalName = $this->nameOf($tree['guitars']);

        try {
            ($this->edit)(new EditCategory(
                $tree['guitars']->id()->toString(),
                'Renamed Guitars',
                'guitars',
                $tree['solidBody']->id()->toString(),
            ));
            self::fail('Expected CategoryMoveWouldCreateCycle.');
        } catch (CategoryMoveWouldCreateCycle) {
        }

        $this->em->flush();

        self::assertSame($originalName, $this->nameOf($tree['guitars']));
        self::assertNull($this->parentOf($tree['guitars']));
    }

    /**
     * A rollback check through a fresh aggregate. It clears the EM itself, so it says nothing
     * about the lock adapter's clear().
     */
    public function testAFailedEditLeavesTheStoredNameUnchanged(): void
    {
        $tree = $this->guitarsTree();
        $id = $tree['guitars']->id();

        try {
            ($this->edit)(new EditCategory($id->toString(), 'Renamed', 'guitars', $tree['solidBody']->id()->toString()));
            self::fail('Expected CategoryMoveWouldCreateCycle.');
        } catch (CategoryMoveWouldCreateCycle) {
        }

        $this->em->clear();
        $fresh = self::getContainer()->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $fresh);
        self::assertSame('Guitars', $fresh->get($id)->name()->toString());
    }
}
