<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Listing;

use App\Infrastructure\Listing\Form\LeafCategoryChoices;
use PHPUnit\Framework\TestCase;

/** AC-24 (the select's content): leaves only, full-path labels, outline order. */
final class LeafCategoryChoicesTest extends TestCase
{
    /**
     * The spec's fixture tree in the admin outline's depth-first order, plus a second root so a
     * leaf at depth 1 is covered.
     *
     * @return list<array{id: string, parentId: ?string, name: string, depth: int}>
     */
    private static function outline(): array
    {
        return [
            ['id' => 'i', 'parentId' => null, 'name' => 'Instruments', 'depth' => 1],
            ['id' => 'g', 'parentId' => 'i', 'name' => 'Guitars', 'depth' => 2],
            ['id' => 'e', 'parentId' => 'g', 'name' => 'Electric', 'depth' => 3],
            ['id' => 'b', 'parentId' => 'i', 'name' => 'Bass', 'depth' => 2],
            ['id' => 'a', 'parentId' => null, 'name' => 'Accessories', 'depth' => 1],
        ];
    }

    public function testLeavesAreOnlyCategoriesNobodyIsFiledUnderLabelledByTheirFullPathInOutlineOrder(): void
    {
        self::assertSame(
            ['e' => 'Instruments › Guitars › Electric', 'b' => 'Instruments › Bass', 'a' => 'Accessories'],
            LeafCategoryChoices::leaves(self::outline()),
        );
    }

    public function testPathsCoverEveryCategory(): void
    {
        self::assertSame(
            [
                'i' => 'Instruments',
                'g' => 'Instruments › Guitars',
                'e' => 'Instruments › Guitars › Electric',
                'b' => 'Instruments › Bass',
                'a' => 'Accessories',
            ],
            LeafCategoryChoices::paths(self::outline()),
        );
    }

    public function testSiblingsWithTheSameNameAreBothKept(): void
    {
        $rows = [
            ['id' => 'r', 'parentId' => null, 'name' => 'Root', 'depth' => 1],
            ['id' => 'x1', 'parentId' => 'r', 'name' => 'Same', 'depth' => 2],
            ['id' => 'x2', 'parentId' => 'r', 'name' => 'Same', 'depth' => 2],
        ];

        self::assertSame(['x1' => 'Root › Same', 'x2' => 'Root › Same'], LeafCategoryChoices::leaves($rows));
    }

    public function testNoCategoriesGiveNoChoices(): void
    {
        self::assertSame([], LeafCategoryChoices::leaves([]));
    }
}
