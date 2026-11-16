<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\Entity;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle;
use App\Domain\Catalog\Exception\CategoryMoveWouldDuplicateAttributeCode;
use App\Domain\Catalog\Exception\CategoryTreeTooDeep;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CategoryTest extends TestCase
{
    private static function id(): CategoryId
    {
        return CategoryId::fromString(Uuid::v7()->toRfc4122());
    }

    /**
     * @param list<CategoryId> $lineage
     */
    private static function lineage(array $lineage): Lineage
    {
        return [] === $lineage ? Lineage::ofRoot() : Lineage::fromRootDown($lineage);
    }

    /**
     * @param list<CategoryId> $parentLineage
     */
    private static function category(array $parentLineage = [], ?CategoryId $id = null): Category
    {
        return Category::create(
            $id ?? self::id(),
            CategoryName::fromString('Guitars'),
            CategorySlug::fromString('guitars'),
            self::lineage($parentLineage),
        );
    }

    public function testCreatedUnderTheRootHasNoParent(): void
    {
        self::assertNull(self::category()->parentId());
    }

    public function testCreatedUnderADepthThreeLineageHasItsLastIdAsParent(): void
    {
        $lineage = [self::id(), self::id(), self::id()];

        $category = self::category($lineage);

        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($lineage[2]));
    }

    public function testCreatingUnderADepthFourLineageIsTooDeep(): void
    {
        $this->expectException(CategoryTreeTooDeep::class);

        self::category([self::id(), self::id(), self::id(), self::id()]);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function cyclicTargets(): iterable
    {
        // Symbols: S = the category itself, C = its child, G = its grandchild.
        yield 'under itself' => [['S']];
        yield 'under a child' => [['S', 'C']];
        yield 'under a grandchild' => [['S', 'C', 'G']];
    }

    /**
     * @param list<string> $symbols
     */
    #[DataProvider('cyclicTargets')]
    public function testMovingUnderItselfOrADescendantIsRefused(array $symbols): void
    {
        $self = self::id();
        $ids = ['S' => $self, 'C' => self::id(), 'G' => self::id()];
        $category = self::category([], $self);

        try {
            $category->moveUnder(self::lineage(array_map(static fn (string $s): CategoryId => $ids[$s], $symbols)), 1, AttributeCodes::none(), AttributeCodes::none());
            self::fail('Expected CategoryMoveWouldCreateCycle.');
        } catch (CategoryMoveWouldCreateCycle) {
            self::assertNull($category->parentId());
        }
    }

    public function testCycleIsReportedBeforeDepthWhenBothApply(): void
    {
        $self = self::id();
        $category = self::category([], $self);

        $this->expectException(CategoryMoveWouldCreateCycle::class);

        $category->moveUnder(self::lineage([$self, self::id(), self::id(), self::id()]), 1, AttributeCodes::none(), AttributeCodes::none());
    }

    public function testMoveSucceedsWhenDepthPlusHeightEqualsFour(): void
    {
        $parent = self::id();
        $category = self::category();

        $category->moveUnder(self::lineage([self::id(), self::id(), $parent]), 1, AttributeCodes::none(), AttributeCodes::none());

        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($parent));
    }

    public function testMoveIsTooDeepWhenDepthPlusHeightEqualsFive(): void
    {
        $category = self::category();

        try {
            $category->moveUnder(self::lineage([self::id(), self::id(), self::id()]), 2, AttributeCodes::none(), AttributeCodes::none());
            self::fail('Expected CategoryTreeTooDeep.');
        } catch (CategoryTreeTooDeep) {
            self::assertNull($category->parentId());
        }
    }

    public function testMoveToRootSucceedsForEveryHeight(): void
    {
        foreach ([1, 2, 3, 4] as $height) {
            $category = self::category([self::id()]);

            $category->moveUnder(Lineage::ofRoot(), $height, AttributeCodes::none(), AttributeCodes::none());

            self::assertNull($category->parentId(), "height $height");
        }
    }

    public function testASubtreeHeightBelowOneIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::category()->moveUnder(Lineage::ofRoot(), 0, AttributeCodes::none(), AttributeCodes::none());
    }

    public function testRenameAndChangeSlugKeepIdentityAndParent(): void
    {
        $parent = self::id();
        $id = self::id();
        $category = self::category([$parent], $id);

        $category->rename(CategoryName::fromString('Electric Guitars'));
        $category->changeSlug(CategorySlug::fromString('electric'));

        self::assertSame('Electric Guitars', $category->name()->toString());
        self::assertSame('electric', $category->slug()->toString());
        self::assertTrue($category->id()->equals($id));
        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($parent));
    }

    public function testMovingUnderTheCurrentParentIsANoOp(): void
    {
        $parent = self::id();
        $category = self::category([$parent]);

        // Height 4 would be too deep for any other placement; the no-op must not even look.
        $category->moveUnder(self::lineage([self::id(), self::id(), self::id(), $parent]), 4, AttributeCodes::none(), AttributeCodes::none());

        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($parent));
    }

    public function testMovingARootToTheRootIsANoOp(): void
    {
        $category = self::category();

        $category->moveUnder(Lineage::ofRoot(), 4, AttributeCodes::none(), AttributeCodes::none());

        self::assertNull($category->parentId());
    }

    private static function codes(string ...$values): AttributeCodes
    {
        return AttributeCodes::of(...array_map(AttributeCode::fromString(...), $values));
    }

    public function testMoveSucceedsWhenTheCodeSetsAreDisjoint(): void
    {
        $parent = self::id();
        $category = self::category();

        $category->moveUnder(self::lineage([$parent]), 1, self::codes('condition', 'strings'), self::codes('pickups', 'scale'));

        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($parent));
    }

    public function testMoveWithASharedCodeIsRefusedNamingItAndKeepsTheParent(): void
    {
        $oldParent = self::id();
        $category = self::category([$oldParent]);

        try {
            $category->moveUnder(self::lineage([self::id()]), 1, self::codes('condition', 'scale'), self::codes('pickups', 'scale'));
            self::fail('Expected CategoryMoveWouldDuplicateAttributeCode.');
        } catch (CategoryMoveWouldDuplicateAttributeCode $e) {
            self::assertStringContainsString('scale', $e->getMessage());
            self::assertSame('scale', $e->code()->toString());
            self::assertNotNull($category->parentId());
            self::assertTrue($category->parentId()->equals($oldParent));
        }
    }

    public function testACycleThatAlsoCollidesIsReportedAsTheCycle(): void
    {
        $self = self::id();
        $category = self::category([], $self);

        $this->expectException(CategoryMoveWouldCreateCycle::class);

        $category->moveUnder(self::lineage([$self, self::id()]), 1, self::codes('scale'), self::codes('scale'));
    }

    public function testATooDeepMoveThatAlsoCollidesIsReportedAsTooDeep(): void
    {
        $category = self::category();

        $this->expectException(CategoryTreeTooDeep::class);

        $category->moveUnder(self::lineage([self::id(), self::id(), self::id()]), 2, self::codes('scale'), self::codes('scale'));
    }

    public function testMovingUnderTheCurrentParentIgnoresCollidingCodeSets(): void
    {
        $parent = self::id();
        $category = self::category([$parent]);

        $category->moveUnder(self::lineage([$parent]), 1, self::codes('scale'), self::codes('scale'));

        self::assertNotNull($category->parentId());
        self::assertTrue($category->parentId()->equals($parent));
    }

    public function testPublicSurfaceIsTheSliceOneAllowList(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Category::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(['changeSlug', 'create', 'id', 'moveUnder', 'name', 'parentId', 'rename', 'slug'], $methods);
    }

    public function testCategoryRecordsNoDomainEvents(): void
    {
        self::assertSame([], class_uses(Category::class));
        self::assertFalse((new \ReflectionClass(Category::class))->hasMethod('releaseEvents'));
    }
}
