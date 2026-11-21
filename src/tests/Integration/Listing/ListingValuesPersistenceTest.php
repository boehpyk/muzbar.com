<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\AttributeValue;
use App\Domain\Listing\ValueObject\AttributeValues;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function Zenstruck\Foundry\Persistence\refresh;

/**
 * AC-17: values persist only through `ListingRepository::save()`, and hydrate on every load.
 * (ADR-0020: `listing_attribute_value` is unmapped, so the ORM neither writes nor reads it.).
 */
final class ListingValuesPersistenceTest extends KernelTestCase
{
    use BuildsTheValuesTree;

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
        $this->buildTheValuesTree();
    }

    private function rowCount(): int
    {
        $count = $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function expectedStringsAndSerial(): AttributeValues
    {
        return AttributeValues::of(
            AttributeValue::ofNumber(AttributeId::fromString($this->strings->id()->toString()), 6),
            AttributeValue::ofText(AttributeId::fromString($this->serial->id()->toString()), 'SN-1'),
        );
    }

    private function listingWithValues(): Listing
    {
        return ListingFactory::new()->inCategory($this->electric)->withValues([
            $this->strings->id()->toString() => '6',
            $this->serial->id()->toString() => 'SN-1',
        ])->create();
    }

    public function testWithValuesWritesTheRowsThroughTheRepositoryAndTheInstanceCarriesThem(): void
    {
        $listing = $this->listingWithValues();

        self::assertSame(2, $this->rowCount());
        self::assertTrue($listing->attributeValues()->equals($this->expectedStringsAndSerial()));
    }

    public function testAFactoryListingWithoutValuesWritesNoRows(): void
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->create();

        self::assertSame(0, $this->rowCount());
        self::assertTrue($listing->attributeValues()->isEmpty());
    }

    public function testAnEntityManagerFindInAFreshEntityManagerCarriesTheStoredValues(): void
    {
        $listing = $this->listingWithValues();
        $id = $listing->id();

        $this->em->clear();
        $loaded = $this->em->find(Listing::class, $id);

        self::assertNotNull($loaded);
        self::assertNotSame($listing, $loaded);
        self::assertTrue($loaded->attributeValues()->equals($this->expectedStringsAndSerial()));
    }

    public function testAFoundryRefreshCarriesTheStoredValues(): void
    {
        $listing = $this->listingWithValues();

        $refreshed = refresh($listing);

        self::assertInstanceOf(Listing::class, $refreshed);
        self::assertTrue($refreshed->attributeValues()->equals($this->expectedStringsAndSerial()));
    }

    public function testAFlushAloneWritesNoValueRowsAndAFreshLoadSeesNone(): void
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->create();
        $listing->revise(
            $listing->sellerId(),
            $listing->categoryId(),
            CategoryStatus::Leaf,
            AttributeSchema::of(AttributeRule::text(AttributeId::fromString($this->serial->id()->toString()), false)),
            $listing->title(),
            $listing->description(),
            $listing->price(),
            AttributeInput::fromArray([$this->serial->id()->toString() => 'SN-1']),
        );

        $this->em->flush();

        self::assertSame(0, $this->rowCount(), 'The ORM must not be a writer of listing_attribute_value; only ListingRepository::save() is.');
        $this->em->clear();
        $loaded = $this->em->find(Listing::class, $listing->id());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->attributeValues()->isEmpty());
    }

    public function testTheFactoryRefusesAValueThatBreaksItsRuleSoAFixtureCannotHideAnL9Violation(): void
    {
        $this->expectException(InvalidAttributeValue::class);

        ListingFactory::new()->inCategory($this->electric)->withValues([$this->strings->id()->toString() => '13'])->create();
    }
}
