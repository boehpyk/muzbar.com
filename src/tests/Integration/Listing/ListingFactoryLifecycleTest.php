<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * T16: `ListingFactory::published()` / `withdrawn()` go through the aggregate, persist through the
 * repository and leave no recorded event behind (ADR-0008's Foundry hole).
 */
final class ListingFactoryLifecycleTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->buildTheValuesTree();
    }

    /**
     * @return array<string, string>
     */
    private function requiredValues(): array
    {
        $all = $this->allFiveElectricInputs();

        return [
            $this->condition->id()->toString() => $all[$this->condition->id()->toString()],
            $this->brand->id()->toString() => $all[$this->brand->id()->toString()],
        ];
    }

    private function fresh(ListingId $id): Listing
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $repository = self::getContainer()->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);

        return $repository->get($id);
    }

    public function testPublishedBuildsAPublicListingStampedWithTheFixedInstantAndItsValuesStored(): void
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->withValues($this->requiredValues())->published()->create();

        $row = $this->db->fetchAssociative('SELECT status, published_at FROM listing_listing WHERE id = ?', [$listing->id()->toString()]);
        self::assertIsArray($row);
        self::assertSame('published', $row['status']);
        self::assertIsString($row['published_at']);
        self::assertSame(ListingFactory::PUBLISHED_AT, (new \DateTimeImmutable($row['published_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ?', [$listing->id()->toString()]));
    }

    public function testPublishedReleasesTheEventsSoNoPhantomListingPublishedSurvives(): void
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->withValues($this->requiredValues())->published()->create();

        self::assertSame([], $listing->releaseEvents());
    }

    public function testPublishedWithARequiredDetailMissingThrowsInsteadOfFabricatingAnIncompleteListing(): void
    {
        $this->expectException(ListingIncomplete::class);

        ListingFactory::new()->inCategory($this->electric)->published()->create();
    }

    public function testWithdrawnIsWithdrawnKeepsPublishedAtAndLeavesNoEvent(): void
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->withValues($this->requiredValues())->withdrawn()->create();

        $loaded = $this->fresh($listing->id());
        self::assertSame(ListingStatus::Withdrawn, $loaded->status());
        self::assertSame(ListingFactory::PUBLISHED_AT, $loaded->publishedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame([], $listing->releaseEvents());
    }

    public function testADefaultFactoryListingStaysADraftWithNoPublishedAt(): void
    {
        $listing = ListingFactory::createOne();

        self::assertSame(ListingStatus::Draft, $this->fresh($listing->id())->status());
        self::assertNull($this->db->fetchOne('SELECT published_at FROM listing_listing WHERE id = ?', [$listing->id()->toString()]));
    }
}
