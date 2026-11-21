<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Command\WithdrawListing;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Application\Listing\Handler\WithdrawListingHandler;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Event\ListingPublished;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Exception\ListingNotPublished;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\SellerId;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * listing-publish AC-9, AC-10, AC-12 and AC-13 against the real `muzbar_test` database (DAMA
 * rollback), with a recording `DomainEventDispatcher` and a Clock frozen at T = 2026-11-20 10:00:00
 * UTC. Every read-back that must reflect a handler's write clears the EntityManager first.
 * "Unchanged" means both listing tables, every column, before and after. Ann owns draft D on Electric.
 */
final class ListingPublishHandlersTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private ReviseListingHandler $revise;
    private WithdrawListingHandler $withdraw;
    private SpyDomainEventDispatcher $events;
    private Connection $db;
    private EntityManagerInterface $em;
    private string $ann;
    private string $bob;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $listings = $container->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $listings);
        $categories = $container->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $this->events = new SpyDomainEventDispatcher();
        $this->revise = new ReviseListingHandler($listings, $categories, new FrozenClock(self::t()), $this->events);
        $this->withdraw = new WithdrawListingHandler($listings, $categories);
        $this->db = $db;
        $this->em = $em;

        $this->buildTheValuesTree();
        $this->ann = Uuid::v7()->toRfc4122();
        $this->bob = Uuid::v7()->toRfc4122();
    }

    private static function t(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, string> "Complete D": condition = Used, brand = Fender
     */
    private function completeInputs(): array
    {
        return [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ];
    }

    private function draft(): ListingId
    {
        $listing = ListingFactory::new()->inCategory($this->electric)->soldBy(SellerId::fromString($this->ann))->create();

        return $listing->id();
    }

    private function publishedD(): ListingId
    {
        return ListingFactory::new()->inCategory($this->electric)
            ->soldBy(SellerId::fromString($this->ann))
            ->withValues([...$this->completeInputs(), $this->strings->id()->toString() => '6'])
            ->published(self::t())
            ->create()->id();
    }

    private function withdrawnD(): ListingId
    {
        return ListingFactory::new()->inCategory($this->electric)
            ->soldBy(SellerId::fromString($this->ann))
            ->withValues([...$this->completeInputs(), $this->strings->id()->toString() => '6'])
            ->withdrawn(self::t())
            ->create()->id();
    }

    /**
     * @param array<string, string> $attributes
     */
    private function revision(ListingId $id, array $attributes, bool $publish, string $title = 'New title', ?string $by = null): ReviseListing
    {
        return new ReviseListing($id->toString(), $by ?? $this->ann, $this->electric->id()->toString(), $title, 'Desc', '1234.50', 'EUR', $attributes, $publish);
    }

    private function fresh(ListingId $id): Listing
    {
        $this->em->clear();
        $repository = self::getContainer()->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);

        return $repository->get($id);
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id'),
            $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id'),
        ];
    }

    private function publishedAtColumn(ListingId $id): mixed
    {
        return $this->db->fetchOne('SELECT published_at FROM listing_listing WHERE id = ?', [$id->toString()]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    // ---------------------------------------------------------------- AC-9

    public function testSaveAndPublishPublishesStoresTheValuesStampsTheClockAndDispatchesOneEvent(): void
    {
        $id = $this->draft();

        ($this->revise)($this->revision($id, $this->completeInputs(), true));

        $listing = $this->fresh($id);
        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertEquals(self::t(), $listing->publishedAt());
        self::assertSame('New title', $listing->title()->toString());
        self::assertSame(2, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ?', [$id->toString()]));

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(ListingPublished::class, $dispatched[0]);
        self::assertSame($id->toString(), $dispatched[0]->listingId()->toString());
        self::assertSame($this->ann, $dispatched[0]->sellerId()->toString());
        self::assertSame($this->electric->id()->toString(), $dispatched[0]->categoryId()->toString());
        self::assertEquals(self::t(), $dispatched[0]->publishedAt());
    }

    public function testASaveWithoutPublishKeepsTheDraftADraftAndDispatchesNothing(): void
    {
        $id = $this->draft();

        ($this->revise)($this->revision($id, $this->completeInputs(), false));

        self::assertSame(ListingStatus::Draft, $this->fresh($id)->status());
        self::assertNull($this->publishedAtColumn($id));
        self::assertSame([], $this->events->dispatched());
    }

    public function testSaveAndPublishAgainAfterAWithdrawMovesPublishedAtToTheClock(): void
    {
        $id = $this->withdrawnD();
        $later = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));
        $listings = $this->service(ListingRepository::class);
        $revise = new ReviseListingHandler($listings, $this->service(Categories::class), new FrozenClock($later), $this->events);

        $revise($this->revision($id, $this->completeInputs(), true));

        self::assertSame(ListingStatus::Published, $this->fresh($id)->status());
        self::assertEquals($later, $this->fresh($id)->publishedAt());
        self::assertCount(1, $this->events->dispatched());
    }

    // ---------------------------------------------------------------- AC-10

    public function testARefusedPublishWritesNothingNotEvenTheTitleEditAndDispatchesNothing(): void
    {
        $id = $this->draft();
        $before = $this->snapshot();

        try {
            // A new title, brand empty: the whole save is refused, not half-applied.
            ($this->revise)($this->revision($id, [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used')], true, 'A brand new title'));
            self::fail('An incomplete listing was published.');
        } catch (ListingIncomplete $e) {
            self::assertSame([$this->brand->id()->toString()], array_map(static fn (AttributeId $a): string => $a->toString(), $e->attributeIds()));
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->events->dispatched());
    }

    // ---------------------------------------------------------------- AC-12

    private function adminTightensTheSchema(): string
    {
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->hasCase->id()->toString(), 'Has case', 'has-case', true));
        ($this->service(DefineAttributeHandler::class))(new DefineAttribute($this->instruments->id()->toString(), 'text', 'Colour', 'colour', true));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->strings->id()->toString(), 'Strings', 'strings', false, 'pcs', 7, 12));

        $colour = $this->db->fetchOne('SELECT id FROM catalog_attribute WHERE code = ?', ['colour']);
        self::assertIsString($colour);

        return $colour;
    }

    public function testAnAdminTighteningTheSchemaNeverTouchesAPublishedListing(): void
    {
        $id = $this->publishedD();
        $before = $this->snapshot();

        $this->adminTightensTheSchema();

        self::assertSame($before, $this->snapshot());
        self::assertSame(ListingStatus::Published, $this->fresh($id)->status());
        self::assertSame([], $this->events->dispatched());
    }

    public function testAfterTighteningThePublishedSellersNextSaveMeetsTheCurrentRulesInOrder(): void
    {
        $id = $this->publishedD();
        $colour = $this->adminTightensTheSchema();
        $hasCase = $this->hasCase->id()->toString();
        $old = [...$this->completeInputs(), $this->strings->id()->toString() => '6'];
        $before = $this->snapshot();

        // 1. Only the old fields: the stale 6 is out of 7…12 — L-9 before L-13.
        try {
            ($this->revise)($this->revision($id, $old, false));
            self::fail('The stale 6 was accepted.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::OutOfRange, $e->reason());
        }
        self::assertSame($before, $this->snapshot());

        // 2. strings fixed: now has_case and the new attribute are named, together.
        try {
            ($this->revise)($this->revision($id, [...$old, $this->strings->id()->toString() => '8'], false));
            self::fail('A published listing was saved incomplete.');
        } catch (ListingIncomplete $e) {
            $named = array_map(static fn (AttributeId $a): string => $a->toString(), $e->attributeIds());
            sort($named);
            $expected = [$hasCase, $colour];
            sort($expected);
            self::assertSame($expected, $named);
        }
        self::assertSame($before, $this->snapshot());

        // 3. Everything filled: saved, still published, published_at untouched, nothing dispatched.
        $publishedAt = $this->publishedAtColumn($id);
        ($this->revise)($this->revision($id, [...$old, $this->strings->id()->toString() => '8', $hasCase => 'yes', $colour => 'Sunburst'], false));

        $listing = $this->fresh($id);
        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertSame($publishedAt, $this->publishedAtColumn($id));
        self::assertSame(5, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ?', [$id->toString()]));
        self::assertSame([], $this->events->dispatched());
    }

    public function testAfterTighteningAWithdrawnListingSavesWithoutTheNewFieldsButCannotBePublishedWithoutThem(): void
    {
        $id = $this->withdrawnD();
        $this->adminTightensTheSchema();
        $inputs = [...$this->completeInputs(), $this->strings->id()->toString() => '8'];

        ($this->revise)($this->revision($id, $inputs, false, 'Saved while withdrawn'));

        self::assertSame('Saved while withdrawn', $this->fresh($id)->title()->toString());
        self::assertSame(ListingStatus::Withdrawn, $this->fresh($id)->status());
        $before = $this->snapshot();

        try {
            ($this->revise)($this->revision($id, $inputs, true, 'Not saved'));
            self::fail('A withdrawn listing was published incomplete.');
        } catch (ListingIncomplete) {
            self::assertSame($before, $this->snapshot());
            self::assertSame([], $this->events->dispatched());
        }
    }

    // ---------------------------------------------------------------- AC-13

    public function testWithdrawingAPublishedListingKeepsPublishedAtAndTheValues(): void
    {
        $id = $this->publishedD();
        $values = $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY attribute_id');
        $publishedAt = $this->publishedAtColumn($id);

        ($this->withdraw)(new WithdrawListing($id->toString(), $this->ann));

        self::assertSame(ListingStatus::Withdrawn, $this->fresh($id)->status());
        self::assertSame('withdrawn', $this->db->fetchOne('SELECT status FROM listing_listing WHERE id = ?', [$id->toString()]));
        self::assertSame($publishedAt, $this->publishedAtColumn($id));
        self::assertSame($values, $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY attribute_id'));
    }

    public function testWithdrawingADraftIsRefusedAndTheRowIsUnchanged(): void
    {
        $id = $this->draft();
        $before = $this->snapshot();

        $this->expectException(ListingNotPublished::class);

        try {
            ($this->withdraw)(new WithdrawListing($id->toString(), $this->ann));
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testWithdrawingSomeoneElsesListingIsRefusedAndTheRowIsUnchanged(): void
    {
        $id = $this->publishedD();
        $before = $this->snapshot();

        $this->expectException(NotTheSeller::class);

        try {
            ($this->withdraw)(new WithdrawListing($id->toString(), $this->bob));
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testWithdrawingAWithdrawnListingChangesNothing(): void
    {
        $id = $this->withdrawnD();
        $before = $this->snapshot();

        ($this->withdraw)(new WithdrawListing($id->toString(), $this->ann));

        self::assertSame($before, $this->snapshot());
    }
}
