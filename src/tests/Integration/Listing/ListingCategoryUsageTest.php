<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-17 (listing-draft), second bullet: `CategoryUsage::hasListings()` against the real listing table;
 * and C-27's fact (listing-attribute-values): `hasAttributeValues()`.
 */
final class ListingCategoryUsageTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private CategoryUsage $usage;

    protected function setUp(): void
    {
        self::bootKernel();
        $usage = self::getContainer()->get(CategoryUsage::class);
        self::assertInstanceOf(CategoryUsage::class, $usage);
        $this->usage = $usage;
    }

    public function testTrueForACategoryWithADraftFalseForOneWithoutAndForAnUnknownId(): void
    {
        $electric = CategoryFactory::createOne();
        $bass = CategoryFactory::createOne();
        ListingFactory::new()->inCategory($electric)->create();

        self::assertTrue($this->usage->hasListings(CategoryId::fromString($electric->id()->toString())));
        self::assertFalse($this->usage->hasListings(CategoryId::fromString($bass->id()->toString())));
        self::assertFalse($this->usage->hasListings(CategoryId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testAnotherSellersListingCountsToo(): void
    {
        $electric = CategoryFactory::createOne();
        ListingFactory::new()->inCategory($electric)->create();
        ListingFactory::new()->inCategory($electric)->create();

        self::assertTrue($this->usage->hasListings(CategoryId::fromString($electric->id()->toString())));
    }

    public function testAListingOfAnotherCategoryDoesNotCount(): void
    {
        $electric = CategoryFactory::createOne();
        $bass = CategoryFactory::createOne();
        ListingFactory::new()->inCategory($bass)->create();

        self::assertFalse($this->usage->hasListings(CategoryId::fromString($electric->id()->toString())));
    }

    /**
     * @param list<Category> $categories
     */
    private function holdsValues(array $categories): bool
    {
        return $this->usage->hasAttributeValues(array_map(static fn ($c): CategoryId => $c->id(), $categories));
    }

    public function testAListingWithAValueMakesItsCategoryHoldValues(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();

        self::assertTrue($this->holdsValues([$this->electric]));
        self::assertFalse($this->holdsValues([$this->bass]));
        self::assertFalse($this->holdsValues([$this->guitars]), 'Only the listed category counts; the subtree is the caller\'s list.');
    }

    public function testASubtreeListHoldsValuesWhenAnyMemberDoes(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->strings->id()->toString() => '6'])->create();

        self::assertTrue($this->holdsValues([$this->guitars, $this->electric]));
        self::assertTrue($this->holdsValues([$this->bass, $this->electric]));
        self::assertFalse($this->holdsValues([$this->instruments, $this->guitars, $this->bass]));
    }

    public function testListingsWithNoValuesDoNotHoldValues(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->create();

        self::assertTrue($this->usage->hasListings($this->electric->id()));
        self::assertFalse($this->holdsValues([$this->electric]));
    }

    public function testAnEmptyListHoldsNoValues(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();

        self::assertFalse($this->usage->hasAttributeValues([]));
    }

    public function testAnUnknownCategoryHoldsNoValues(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();

        self::assertFalse($this->usage->hasAttributeValues([CategoryId::fromString(Uuid::v7()->toRfc4122())]));
    }
}
