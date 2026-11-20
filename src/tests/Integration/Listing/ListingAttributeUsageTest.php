<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Port\AttributeUsage;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** C-25 / C-26's fact: `AttributeUsage` against the real `listing_attribute_value` table. */
final class ListingAttributeUsageTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private AttributeUsage $usage;

    protected function setUp(): void
    {
        self::bootKernel();
        $usage = self::getContainer()->get(AttributeUsage::class);
        self::assertInstanceOf(AttributeUsage::class, $usage);
        $this->usage = $usage;
        $this->buildTheValuesTree();
    }

    private function attribute(Attribute $attribute): AttributeId
    {
        return $attribute->id();
    }

    private function option(Attribute $attribute, string $label): AttributeOptionId
    {
        return AttributeOptionId::fromString($this->optionIdOf($attribute, $label));
    }

    public function testAnAttributeIsInUseOnlyWhileSomeListingHoldsAValueForIt(): void
    {
        ListingFactory::new()->inCategory($this->electric)->withValues([
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ])->create();

        self::assertTrue($this->usage->isInUse($this->attribute($this->brand)));
        self::assertFalse($this->usage->isInUse($this->attribute($this->serial)));
        self::assertFalse($this->usage->isInUse($this->attribute($this->condition)));
        self::assertFalse($this->usage->isInUse(AttributeId::fromString(Uuid::v7()->toRfc4122())));
    }

    public function testAListingWithNoValuesDoesNotPutAnyAttributeInUse(): void
    {
        ListingFactory::new()->inCategory($this->electric)->create();

        self::assertFalse($this->usage->isInUse($this->attribute($this->brand)));
        self::assertFalse($this->usage->isInUse($this->attribute($this->serial)));
    }

    public function testAnotherSellersListingPutsTheAttributeInUseToo(): void
    {
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();
        ListingFactory::new()->inCategory($this->electric)->create();

        self::assertTrue($this->usage->isInUse($this->attribute($this->serial)));
    }

    public function testAnOptionIsInUseOnlyWhenAValueNamesThatOption(): void
    {
        ListingFactory::new()->inCategory($this->electric)->withValues([
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ])->create();

        self::assertTrue($this->usage->isOptionInUse($this->attribute($this->brand), $this->option($this->brand, 'Fender')));
        self::assertFalse($this->usage->isOptionInUse($this->attribute($this->brand), $this->option($this->brand, 'Gibson')));
    }

    public function testAnOptionIdPairedWithTheWrongAttributeIsNotInUse(): void
    {
        ListingFactory::new()->inCategory($this->electric)->withValues([
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ])->create();

        self::assertFalse($this->usage->isOptionInUse($this->attribute($this->condition), $this->option($this->brand, 'Fender')));
    }

    public function testAnOptionOfAnAttributeWhoseValuesAreNotChoicesIsNeverInUse(): void
    {
        ListingFactory::new()->inCategory($this->electric)->withValues([$this->strings->id()->toString() => '6'])->create();

        self::assertFalse($this->usage->isOptionInUse($this->attribute($this->brand), $this->option($this->brand, 'Fender')));
    }
}
