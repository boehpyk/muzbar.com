<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-17 (listing-draft), second bullet: `CategoryUsage::hasListings()` against the real listing table.
 * C-27's fact (`hasAttributeValues()`, listing-attribute-values) was deleted by listing-schema-drift.
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
     * listing-schema-drift (ADR-0022) retired C-27, and with it `hasAttributeValues()`: a move with
     * values is allowed and the values go dormant (L-17). Inverted from the slice-2 tests of that
     * method — the port asks only "is anything filed here?", whether or not the listing holds values.
     */
    public function testThePortAsksNothingAboutValuesSinceC27WasRetired(): void
    {
        $this->buildTheValuesTree();
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(CategoryUsage::class))->getMethods(),
        );

        self::assertSame(['hasListings'], $methods);
        self::assertTrue($this->usage->hasListings($this->electric->id()));
        self::assertFalse($this->usage->hasListings($this->guitars->id()), 'Only the listed category counts.');
    }
}
