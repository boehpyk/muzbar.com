<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Tests\Factory\CategoryFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** AC-17, first bullet: `Categories::statusOf()` against the real catalog tables. */
final class CategoriesForListingTest extends KernelTestCase
{
    private Categories $categories;

    protected function setUp(): void
    {
        self::bootKernel();
        $categories = self::getContainer()->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $this->categories = $categories;
    }

    public function testStatusOfALeafABranchAndAnUnknownId(): void
    {
        $guitars = CategoryFactory::createOne();
        $electric = CategoryFactory::new()->childOf($guitars)->create();

        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf(CategoryId::fromString($electric->id()->toString())));
        self::assertSame(CategoryStatus::Branch, $this->categories->statusOf(CategoryId::fromString($guitars->id()->toString())));
        self::assertSame(CategoryStatus::Missing, $this->categories->statusOf(CategoryId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testARootWithNoChildrenIsALeaf(): void
    {
        $root = CategoryFactory::createOne();

        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf(CategoryId::fromString($root->id()->toString())));
    }

    public function testACategoryBecomesABranchTheMomentItGainsAChild(): void
    {
        $parent = CategoryFactory::createOne();
        $id = CategoryId::fromString($parent->id()->toString());
        self::assertSame(CategoryStatus::Leaf, $this->categories->statusOf($id));

        CategoryFactory::new()->childOf($parent)->create();

        self::assertSame(CategoryStatus::Branch, $this->categories->statusOf($id));
    }
}
