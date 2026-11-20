<?php

declare(strict_types=1);

namespace App\Tests\Integration\Listing;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Listing\Command\DraftListing;
use App\Application\Listing\Command\ReviseListing;
use App\Application\Listing\Handler\DraftListingHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeValue;
use App\Domain\Listing\ValueObject\AttributeValues;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\SellerId;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AC-9 … AC-12 of listing-draft and AC-7 … AC-10 of listing-attribute-values against the real `muzbar_test` database (DAMA rollback). "Unchanged" means the whole
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
    use BuildsTheValuesTree;

    private DraftListingHandler $draft;
    private ReviseListingHandler $revise;
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

        $this->draft = new DraftListingHandler($listings, $categories);
        $clock = $container->get(Clock::class);
        self::assertInstanceOf(Clock::class, $clock);
        $events = $container->get(DomainEventDispatcher::class);
        self::assertInstanceOf(DomainEventDispatcher::class, $events);
        $this->revise = new ReviseListingHandler($listings, $categories, $clock, $events);
        $this->db = $db;
        $this->em = $em;

        $this->buildTheValuesTree();
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
        return new ReviseListing($id->toString(), $by, $category, $title, 'Changed', $price, $currency, [], false);
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
            ($this->revise)(new ReviseListing(Uuid::v7()->toRfc4122(), $this->ann, $this->bass->id()->toString(), 'Changed title', '', '99', 'UAH', [], false));
            self::fail('Expected ListingNotFound.');
        } catch (ListingNotFound) {
            self::assertSame($before, $this->table());
        }
    }

    public function testRevisingAMalformedListingIdIsListingNotFound(): void
    {
        $this->expectException(ListingNotFound::class);

        ($this->revise)(new ReviseListing('not-a-uuid', $this->ann, $this->bass->id()->toString(), 'Changed title', '', '99', 'UAH', [], false));
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

    // ------------------------------------------------------------------ listing-attribute-values

    /**
     * @param array<string, string> $attributes
     */
    private function reviseDetails(ListingId $id, string $by, string $category, array $attributes, string $title = 'Changed title', string $price = '99'): void
    {
        ($this->revise)(new ReviseListing($id->toString(), $by, $category, $title, 'Changed', $price, 'UAH', $attributes, false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueTable(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id');
    }

    /**
     * @return array<string, array<string, mixed>> the listing's rows by attribute id
     */
    private function valueRowsOf(ListingId $id): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE listing_id = ?', [$id->toString()]) as $row) {
            \assert(\is_string($row['attribute_id']));
            $out[$row['attribute_id']] = $row;
        }

        return $out;
    }

    private function freshListing(ListingId $id): Listing
    {
        $this->em->clear();
        $repository = self::getContainer()->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);

        return $repository->get($id);
    }

    private function aid(Attribute $attribute): string
    {
        return $attribute->id()->toString();
    }

    public function testRevisingWithAllFiveValuesWritesExactlyFiveRowsEachWithOnlyItsTypedColumn(): void
    {
        $id = $this->draftOnElectric();
        $used = $this->optionIdOf($this->condition, 'Used');
        $fender = $this->optionIdOf($this->brand, 'Fender');

        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        $rows = $this->valueRowsOf($id);
        self::assertCount(5, $rows);
        self::assertSame(5, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value'));
        self::assertEquals([$used, null, null, null], $this->typed($rows[$this->aid($this->condition)]));
        self::assertEquals([$fender, null, null, null], $this->typed($rows[$this->aid($this->brand)]));
        self::assertEquals([null, 6, null, null], $this->typed($rows[$this->aid($this->strings)]));
        self::assertEquals([null, null, true, null], $this->typed($rows[$this->aid($this->hasCase)]));
        self::assertEquals([null, null, null, 'SN-1'], $this->typed($rows[$this->aid($this->serial)]));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{mixed, mixed, mixed, mixed}
     */
    private function typed(array $row): array
    {
        return [$row['option_id'], $row['value_int'], $row['value_bool'], $row['value_text']];
    }

    public function testAFreshEntityManagerLoadsEqualValues(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        $expected = AttributeValues::of(
            AttributeValue::ofOption(AttributeId::fromString($this->aid($this->condition)), AttributeOptionId::fromString($this->optionIdOf($this->condition, 'Used'))),
            AttributeValue::ofOption(AttributeId::fromString($this->aid($this->brand)), AttributeOptionId::fromString($this->optionIdOf($this->brand, 'Fender'))),
            AttributeValue::ofNumber(AttributeId::fromString($this->aid($this->strings)), 6),
            AttributeValue::ofBoolean(AttributeId::fromString($this->aid($this->hasCase)), true),
            AttributeValue::ofText(AttributeId::fromString($this->aid($this->serial)), 'SN-1'),
        );

        self::assertTrue($this->freshListing($id)->attributeValues()->equals($expected));
    }

    public function testRevisingAgainWithAClearedFieldAndAChangedNumberLeavesFourRows(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), [...$this->allFiveElectricInputs(), $this->aid($this->serial) => '', $this->aid($this->strings) => '8']);

        $rows = $this->valueRowsOf($id);
        self::assertCount(4, $rows);
        self::assertArrayNotHasKey($this->aid($this->serial), $rows);
        self::assertSame(8, $rows[$this->aid($this->strings)]['value_int']);
        self::assertCount(4, $this->freshListing($id)->attributeValues()->toList());
    }

    public function testRevisingWithNothingChangedLeavesTheSameRows(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $before = $this->valueTable();

        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        self::assertCount(5, $before);
        self::assertSame($before, $this->valueTable());
    }

    public function testRevisingWithNoInputAtAllClearsEveryValue(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), []);

        self::assertSame([], $this->valueTable());
        self::assertTrue($this->freshListing($id)->attributeValues()->isEmpty());
    }

    public function testRevisingOneListingNeverTouchesAnotherListingsRows(): void
    {
        $mine = $this->draftOnElectric();
        $other = $this->draftOnElectric();
        $this->reviseDetails($other, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $theirs = $this->valueRowsOf($other);

        $this->reviseDetails($mine, $this->ann, $this->electric->id()->toString(), [$this->aid($this->strings) => '5']);

        self::assertSame($theirs, $this->valueRowsOf($other));
        self::assertCount(1, $this->valueRowsOf($mine));
    }

    /**
     * Every AC-1 refusal class, sent through the handler against stored values: neither table moves,
     * though the title, price and category in the same revise were valid and different.
     *
     * @return iterable<string, array{string, string, InvalidAttributeValueReason}>
     */
    public static function refusals(): iterable
    {
        yield 'foreign option' => ['brand', '018f5b2a-0000-7000-8000-00000000ffff', InvalidAttributeValueReason::NotAnOption];
        yield 'malformed option' => ['brand', 'not-a-uuid', InvalidAttributeValueReason::NotAnOption];
        yield 'a number that is not whole' => ['strings', '7.5', InvalidAttributeValueReason::NotAWholeNumber];
        yield 'a number out of range' => ['strings', '13', InvalidAttributeValueReason::OutOfRange];
        yield 'a boolean that is not yes or no' => ['has_case', 'Yes', InvalidAttributeValueReason::NotYesOrNo];
        yield 'a multi-line text' => ['serial', "a\nb", InvalidAttributeValueReason::BadText];
        yield 'a text with NUL' => ['serial', "a\0b", InvalidAttributeValueReason::BadText];
        yield 'an attribute of another category' => ['bass_strings', '4', InvalidAttributeValueReason::NotInForce];
    }

    #[DataProvider('refusals')]
    public function testEveryRefusalClassWritesNothingToEitherTable(string $field, string $raw, InvalidAttributeValueReason $reason): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $listings = $this->table();
        $values = $this->valueTable();
        $key = [
            'brand' => $this->aid($this->brand),
            'strings' => $this->aid($this->strings),
            'has_case' => $this->aid($this->hasCase),
            'serial' => $this->aid($this->serial),
            'bass_strings' => $this->aid($this->bassStrings),
        ][$field];

        try {
            $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), [...$this->allFiveElectricInputs(), $key => $raw], title: 'Never saved', price: '1');
            self::fail('The refusal did not happen.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame($reason, $e->reason());
            self::assertSame($key, $e->attributeKey());
        }

        self::assertSame($listings, $this->table());
        self::assertSame($values, $this->valueTable());
    }

    public function testRefilingToBassKeepsOnlyTheConditionRow(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        $this->reviseDetails($id, $this->ann, $this->bass->id()->toString(), $this->allFiveElectricInputs());

        $rows = $this->valueRowsOf($id);
        self::assertSame([$this->aid($this->condition)], array_keys($rows));
        self::assertSame($this->optionIdOf($this->condition, 'Used'), $rows[$this->aid($this->condition)]['option_id']);
        self::assertSame($this->bass->id()->toString(), $this->db->fetchOne('SELECT category_id FROM listing_listing WHERE id = ?', [$id->toString()]));
    }

    public function testRefilingRefusesAnInvalidValueThatItWouldHaveKept(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $listings = $this->table();
        $values = $this->valueTable();

        try {
            $this->reviseDetails($id, $this->ann, $this->bass->id()->toString(), [$this->aid($this->condition) => 'not-an-option']);
            self::fail('A kept value was not checked.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame($this->aid($this->condition), $e->attributeKey());
        }

        self::assertSame($listings, $this->table());
        self::assertSame($values, $this->valueTable());
    }

    public function testNarrowingBoundsIsAllowedAndNeverTouchesTheStoredValue(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $before = $this->valueTable();

        ($this->editAttribute())(new EditAttribute($this->aid($this->strings), 'Strings', 'strings', false, 'pcs', 7, 12));

        self::assertSame($before, $this->valueTable());
        self::assertSame(6, $this->valueRowsOf($id)[$this->aid($this->strings)]['value_int']);
    }

    public function testAfterNarrowingTheNextSaveIsRefusedOnTheOldValueAndAcceptsANewOne(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        ($this->editAttribute())(new EditAttribute($this->aid($this->strings), 'Strings', 'strings', false, 'pcs', 7, 12));

        try {
            $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
            self::fail('The stale 6 was accepted against 7…12.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::OutOfRange, $e->reason());
            self::assertSame($this->aid($this->strings), $e->attributeKey());
            self::assertSame(7, $e->min());
            self::assertSame(12, $e->max());
        }
        self::assertSame(6, $this->valueRowsOf($id)[$this->aid($this->strings)]['value_int']);

        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), [...$this->allFiveElectricInputs(), $this->aid($this->strings) => '7']);

        self::assertSame(7, $this->valueRowsOf($id)[$this->aid($this->strings)]['value_int']);
    }

    public function testMarkingRequiredAndDefiningANewRequiredAttributeLeaveStoredRowsAndTheNextSaveWithoutThem(): void
    {
        $id = $this->draftOnElectric();
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());
        $before = $this->valueTable();

        ($this->editAttribute())(new EditAttribute($this->aid($this->hasCase), 'Has case', 'has-case', true));
        ($this->service(DefineAttributeHandler::class))(new DefineAttribute($this->instruments->id()->toString(), 'text', 'Colour', 'colour', true));

        self::assertSame($before, $this->valueTable());

        // The seller's form was rendered before; the new attribute is simply absent from it.
        $this->reviseDetails($id, $this->ann, $this->electric->id()->toString(), $this->allFiveElectricInputs());

        self::assertSame($before, $this->valueTable());
    }

    private function editAttribute(): EditAttributeHandler
    {
        return $this->service(EditAttributeHandler::class);
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
