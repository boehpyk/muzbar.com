<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** AC-17, second bullet: `CategoryUsage::hasListings()` against the real listing table. */
final class ListingCategoryUsageTest extends KernelTestCase
{
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
}
