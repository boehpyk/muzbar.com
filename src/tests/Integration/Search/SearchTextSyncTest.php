<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Command\WithdrawListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Application\Listing\Handler\WithdrawListingHandler;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-7 (S-19): `search_listing_text` is kept current by the trigger, whichever writer wrote the
 * listing — a handler, a bare Foundry `create()` — and every status. Real database, DAMA.
 *
 * The row is read raw (`document::text`, `title_folded`): the only thing Search ever compares.
 */
final class SearchTextSyncTest extends KernelTestCase
{
    use BuildsTheValuesTree;

    private DraftListingHandler $draft;
    private ReviseListingHandler $revise;
    private WithdrawListingHandler $withdraw;
    private Connection $db;
    private string $ann;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $listings = $container->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $listings);
        $categories = $container->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $clock = $container->get(Clock::class);
        self::assertInstanceOf(Clock::class, $clock);
        $events = $container->get(DomainEventDispatcher::class);
        self::assertInstanceOf(DomainEventDispatcher::class, $events);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);

        $this->draft = new DraftListingHandler($listings, $categories, $clock, $events);
        $this->revise = new ReviseListingHandler($listings, $categories, $clock, $events);
        $this->withdraw = new WithdrawListingHandler($listings, $categories);
        $this->db = $db;

        $this->buildTheValuesTree();
        $this->ann = Uuid::v7()->toRfc4122();
    }

    /**
     * @return array{document: string, title_folded: string}|null
     */
    private function row(string $listingId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT document::text AS document, title_folded FROM search_listing_text WHERE listing_id = ?', [$listingId]);
        if (false === $row) {
            return null;
        }
        self::assertIsString($row['document']);
        self::assertIsString($row['title_folded']);

        return ['document' => $row['document'], 'title_folded' => $row['title_folded']];
    }

    private function documentMatches(string $listingId, string $terms): bool
    {
        return true === $this->db->fetchOne(
            'SELECT document @@ search_tsquery(?) FROM search_listing_text WHERE listing_id = ?',
            [$terms, $listingId],
        );
    }

    private function revision(string $id, string $title, string $description, bool $publish = false): ReviseListing
    {
        $attributes = [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ];

        return new ReviseListing($id, $this->ann, $this->electric->id()->toString(), $title, $description, '100', 'EUR', $attributes, $publish);
    }

    public function testADraftCreatedThroughTheHandlerGetsItsRow(): void
    {
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Strätocaster Special', 'Sunburst finish', '100', 'EUR', [], false))->toString();

        $row = $this->row($id);
        self::assertNotNull($row);
        self::assertSame('Stratocaster Special', $row['title_folded']);
        self::assertTrue($this->documentMatches($id, 'stratocaster sunburst'));
    }

    public function testADraftCreatedByABareFoundryCreateGetsItsRow(): void
    {
        $id = ListingFactory::new()->inCategory($this->electric)->titled('Gibson Les Paul')->create()->id()->toString();

        $row = $this->row($id);
        self::assertNotNull($row);
        self::assertSame('Gibson Les Paul', $row['title_folded']);
    }

    public function testRevisingOnlyTheDescriptionReplacesTheDocument(): void
    {
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Precision', 'oldword', '100', 'EUR', [], false))->toString();
        $before = $this->row($id);
        self::assertTrue($this->documentMatches($id, 'oldword'));

        ($this->revise)($this->revision($id, 'Fender Precision', 'newword'));

        self::assertTrue($this->documentMatches($id, 'newword'), 'The new description word must be found.');
        self::assertFalse($this->documentMatches($id, 'oldword'), 'The old description word must be gone.');
        $after = $this->row($id);
        self::assertNotNull($after);
        self::assertNotNull($before);
        self::assertSame($before['title_folded'], $after['title_folded']);
    }

    public function testAnUpdateOfOnlyTheDescriptionColumnReplacesTheDocument(): void
    {
        // The handler's UPDATE always lists title too (revise() swaps VO instances), so it cannot tell a
        // trigger that watches `title` alone from the real one. A bare statement can.
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Precision', 'oldword', '100', 'EUR', [], false))->toString();

        $this->db->executeStatement('UPDATE listing_listing SET description = ? WHERE id = ?', ['newword', $id]);

        self::assertTrue($this->documentMatches($id, 'newword'));
        self::assertFalse($this->documentMatches($id, 'oldword'));
    }

    public function testRevisingOnlyTheTitleReplacesTheFoldedTitle(): void
    {
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Precision', 'same words', '100', 'EUR', [], false))->toString();

        ($this->revise)($this->revision($id, 'Gibsön Explorer', 'same words'));

        $row = $this->row($id);
        self::assertNotNull($row);
        self::assertSame('Gibson Explorer', $row['title_folded']);
        self::assertTrue($this->documentMatches($id, 'explorer'));
        self::assertFalse($this->documentMatches($id, 'precision'));
        self::assertTrue($this->documentMatches($id, 'same words'));
    }

    public function testPublishingLeavesTheRowIdentical(): void
    {
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Précision', 'some words', '100', 'EUR', [], false))->toString();
        $before = $this->row($id);

        ($this->revise)($this->revision($id, 'Fender Précision', 'some words', true));

        self::assertSame('published', $this->db->fetchOne('SELECT status FROM listing_listing WHERE id = ?', [$id]));
        self::assertSame($before, $this->row($id));
    }

    public function testWithdrawingLeavesTheRowIdentical(): void
    {
        $id = ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Précision', 'some words', '100', 'EUR', [], false))->toString();
        ($this->revise)($this->revision($id, 'Fender Précision', 'some words', true));
        $before = $this->row($id);

        ($this->withdraw)(new WithdrawListing($id, $this->ann));

        self::assertSame('withdrawn', $this->db->fetchOne('SELECT status FROM listing_listing WHERE id = ?', [$id]));
        self::assertNotNull($before);
        self::assertSame($before, $this->row($id));
    }

    public function testEveryListingOfEveryStatusHasExactlyOneRow(): void
    {
        $seller = $this->ann;
        ($this->draft)(new DraftListing($seller, $this->electric->id()->toString(), 'One', '', '100', 'EUR', [], false));
        ListingFactory::new()->inCategory($this->electric)->titled('Two')->create();
        $published = ($this->draft)(new DraftListing($seller, $this->electric->id()->toString(), 'Three', '', '100', 'EUR', [], false))->toString();
        ($this->revise)($this->revision($published, 'Three', '', true));
        ($this->withdraw)(new WithdrawListing($published, $seller));

        $listings = $this->db->fetchOne('SELECT count(*) FROM listing_listing');
        $rows = $this->db->fetchOne('SELECT count(*) FROM search_listing_text');
        $orphans = $this->db->fetchOne('SELECT count(*) FROM listing_listing l WHERE NOT EXISTS (SELECT 1 FROM search_listing_text t WHERE t.listing_id = l.id)');

        self::assertSame(3, $listings);
        self::assertSame(3, $rows);
        self::assertSame(0, $orphans);
    }
}
