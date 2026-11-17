<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\SellerId;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-9 … AC-12 against the real `muzbar_test` database (DAMA rollback). "Unchanged" means the whole
 * `listing_listing` table, every column, compared before and after. A read-back that must reflect
 * a handler's write clears the EntityManager first and asks the repository again, because the
 * identity map would otherwise hand back the very object the handler mutated.
 *
 * PLAN RISK 3, VERIFIED (2026-11-17): `Categories::holdSteady()` followed by
 * `CategoryTreeLock::exclusively()` in one DAMA session — both sequentially and nested — completes
 * without blocking (see the two tests at the bottom). PostgreSQL never makes a session conflict
 * with its own advisory locks, so this only proves there is no self-deadlock; it can NOT observe
 * blocking. Only the second-session probes in `CategoryTreeLockTest` prove the two modes.
 */
final class ListingHandlersTest extends KernelTestCase
{
    private DraftListingHandler $draft;
    private ReviseListingHandler $revise;
    private Connection $db;
    private EntityManagerInterface $em;

    private Category $instruments;
    private Category $guitars;
    private Category $electric;
    private Category $bass;
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

        $this->draft = new DraftListingHandler($listings, $categories);
        $this->revise = new ReviseListingHandler($listings, $categories);
        $this->db = $db;
        $this->em = $em;

        $this->instruments = CategoryFactory::createOne(['name' => CategoryName::fromString('Instruments'), 'slug' => CategorySlug::fromString('instruments')]);
        $this->guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars'), 'slug' => CategorySlug::fromString('guitars')])->childOf($this->instruments)->create();
        $this->electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric'), 'slug' => CategorySlug::fromString('electric')])->childOf($this->guitars)->create();
        $this->bass = CategoryFactory::new(['name' => CategoryName::fromString('Bass'), 'slug' => CategorySlug::fromString('bass')])->childOf($this->instruments)->create();
        $this->ann = Uuid::v7()->toRfc4122();
        $this->bob = Uuid::v7()->toRfc4122();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id');
    }

    private function draftOnElectric(string $price = '1234.50', string $currency = 'EUR'): ListingId
    {
        return ($this->draft)(new DraftListing($this->ann, $this->electric->id()->toString(), 'Fender Precision V', '', $price, $currency));
    }

    private function revision(ListingId $id, string $by, string $category, string $title = 'Changed title', string $price = '99', string $currency = 'UAH'): ReviseListing
    {
        return new ReviseListing($id->toString(), $by, $category, $title, 'Changed', $price, $currency);
    }

    public function testDraftingPersistsExactlyOneRowWithEveryColumnSet(): void
    {
        $id = $this->draftOnElectric();

        $rows = $this->table();
        self::assertCount(1, $rows);
        self::assertSame($id->toString(), $rows[0]['id']);
        self::assertSame('7', $id->toString()[14], 'The id must be a UUIDv7.');
        self::assertSame($this->ann, $rows[0]['seller_id']);
        self::assertSame($this->electric->id()->toString(), $rows[0]['category_id']);
        self::assertSame('Fender Precision V', $rows[0]['title']);
        self::assertSame('', $rows[0]['description']);
        self::assertSame(123450, $rows[0]['price_amount']);
        self::assertSame('EUR', $rows[0]['price_currency']);
        self::assertSame(ListingStatus::Draft->value, $rows[0]['status']);
    }

    public function testDraftingOnABranchIsRefusedAndWritesNothing(): void
    {
        try {
            ($this->draft)(new DraftListing($this->ann, $this->guitars->id()->toString(), 'Fender Precision V', '', '10', 'EUR'));
            self::fail('Expected CategoryIsNotALeaf.');
        } catch (CategoryIsNotALeaf) {
            self::assertSame([], $this->table());
        }
    }

    public function testDraftingOnAWellFormedButUnknownCategoryIsRefusedAndWritesNothing(): void
    {
        try {
            ($this->draft)(new DraftListing($this->ann, Uuid::v7()->toRfc4122(), 'Fender Precision V', '', '10', 'EUR'));
            self::fail('Expected UnknownCategory.');
        } catch (UnknownCategory) {
            self::assertSame([], $this->table());
        }
    }

    public function testDraftingOnAMalformedCategoryIdIsUnknownCategoryAndWritesNothing(): void
    {
        try {
            ($this->draft)(new DraftListing($this->ann, 'not-a-uuid', 'Fender Precision V', '', '10', 'EUR'));
            self::fail('Expected UnknownCategory.');
        } catch (UnknownCategory) {
            self::assertSame([], $this->table());
        }
    }

    public function testTheSellerRevisesTheirDraftAndAFreshReadShowsAllFourChanges(): void
    {
        $id = $this->draftOnElectric();

        ($this->revise)($this->revision($id, $this->ann, $this->bass->id()->toString()));

        $this->em->clear();
        $repository = self::getContainer()->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);
        $listing = $repository->get($id);
        self::assertSame('Changed title', $listing->title()->toString());
        self::assertSame(9900, $listing->price()->amountInMinorUnits());
        self::assertSame(Currency::UAH, $listing->price()->currency());
        self::assertSame($this->bass->id()->toString(), $listing->categoryId()->toString());
        self::assertSame($this->ann, $listing->sellerId()->toString());
    }

    public function testAnotherSellerIsRefusedAndTheRowIsUnchanged(): void
    {
        $id = $this->draftOnElectric();
        $before = $this->table();

        try {
            ($this->revise)($this->revision($id, $this->bob, $this->bass->id()->toString()));
            self::fail('Expected NotTheSeller.');
        } catch (NotTheSeller) {
            self::assertSame($before, $this->table());
        }
    }

    public function testRefilingOntoABranchIsRefusedAndTheRowIsUnchanged(): void
    {
        $id = $this->draftOnElectric();
        $before = $this->table();

        try {
            ($this->revise)($this->revision($id, $this->ann, $this->instruments->id()->toString()));
            self::fail('Expected CategoryIsNotALeaf.');
        } catch (CategoryIsNotALeaf) {
            self::assertSame($before, $this->table());
        }
    }

    public function testRefilingOntoAnUnknownCategoryIsRefusedAndTheRowIsUnchanged(): void
    {
        $id = $this->draftOnElectric();
        $before = $this->table();

        try {
            ($this->revise)($this->revision($id, $this->ann, Uuid::v7()->toRfc4122()));
            self::fail('Expected UnknownCategory.');
        } catch (UnknownCategory) {
            self::assertSame($before, $this->table());
        }
    }

    public function testRevisingAnUnknownListingIsListingNotFound(): void
    {
        $this->draftOnElectric();
        $before = $this->table();

        try {
            ($this->revise)(new ReviseListing(Uuid::v7()->toRfc4122(), $this->ann, $this->bass->id()->toString(), 'Changed title', '', '99', 'UAH'));
            self::fail('Expected ListingNotFound.');
        } catch (ListingNotFound) {
            self::assertSame($before, $this->table());
        }
    }

    public function testRevisingAMalformedListingIdIsListingNotFound(): void
    {
        $this->expectException(ListingNotFound::class);

        ($this->revise)(new ReviseListing('not-a-uuid', $this->ann, $this->bass->id()->toString(), 'Changed title', '', '99', 'UAH'));
    }

    public function testAnInvalidPriceOnReviseLeavesTheRowUnchanged(): void
    {
        $id = $this->draftOnElectric();
        $before = $this->table();

        try {
            ($this->revise)($this->revision($id, $this->ann, $this->bass->id()->toString(), price: '1.234'));
            self::fail('Expected InvalidPrice.');
        } catch (InvalidPrice) {
            self::assertSame($before, $this->table());
        }
    }

    public function testALegacyDraftOnABranchCanBeEditedWhileKeepingItsCategory(): void
    {
        $legacy = ListingFactory::new()->inCategory($this->guitars)->soldBy(SellerId::fromString($this->ann))->create();

        ($this->revise)($this->revision($legacy->id(), $this->ann, $this->guitars->id()->toString()));

        self::assertSame('Changed title', $this->db->fetchOne('SELECT title FROM listing_listing WHERE id = ?', [$legacy->id()->toString()]));
    }

    public function testHoldSteadyThenExclusivelyInOneSessionDoesNotDeadlock(): void
    {
        $categories = self::getContainer()->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);

        $categories->holdSteady(static fn (): null => null);

        self::assertSame('done', $lock->exclusively(static fn (): string => 'done'));
    }

    public function testExclusivelyNestedInsideHoldSteadyInOneSessionDoesNotDeadlock(): void
    {
        $categories = self::getContainer()->get(Categories::class);
        self::assertInstanceOf(Categories::class, $categories);
        $lock = self::getContainer()->get(CategoryTreeLock::class);
        self::assertInstanceOf(CategoryTreeLock::class, $lock);

        $result = $categories->holdSteady(static fn (): string => $lock->exclusively(static fn (): string => 'nested'));

        self::assertSame('nested', $result);
    }
}
