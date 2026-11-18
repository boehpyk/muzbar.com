<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\Entity;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingStatus;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;
use PHPUnit\Framework\TestCase;

/** AC-3, AC-4 (attribute values), plus listing-draft's AC-4, AC-5, AC-6. */
final class ListingTest extends TestCase
{
    private const string ANN = '018f5b2a-0000-7000-8000-0000000000a1';
    private const string BOB = '018f5b2a-0000-7000-8000-0000000000b2';
    private const string ELECTRIC = '018f5b2a-0000-7000-8000-0000000000e1';
    private const string BASS = '018f5b2a-0000-7000-8000-0000000000e2';
    private const string CONDITION = '018f5b2a-0000-7000-8000-00000000a001';
    private const string BRAND = '018f5b2a-0000-7000-8000-00000000a002';
    private const string STRINGS = '018f5b2a-0000-7000-8000-00000000a003';
    private const string HAS_CASE = '018f5b2a-0000-7000-8000-00000000a004';
    private const string SERIAL = '018f5b2a-0000-7000-8000-00000000a005';
    private const string BASS_STRINGS = '018f5b2a-0000-7000-8000-00000000a006';
    private const string NEW = '018f5b2a-0000-7000-8000-00000000b001';
    private const string USED = '018f5b2a-0000-7000-8000-00000000b002';
    private const string FENDER = '018f5b2a-0000-7000-8000-00000000b003';
    private const string GIBSON = '018f5b2a-0000-7000-8000-00000000b004';

    private function draftOnElectric(): Listing
    {
        return Listing::draft(
            ListingId::fromString('018f5b2a-0000-7000-8000-000000000001'),
            SellerId::fromString(self::ANN),
            CategoryId::fromString(self::ELECTRIC),
            CategoryStatus::Leaf,
            ListingTitle::fromString('Fender Precision V'),
            ListingDescription::fromString('Mint'),
            Price::fromMinorUnits(123450, Currency::EUR),
        );
    }

    public function testDraftingOnALeafKeepsExactlyWhatWasPassedAndIsADraft(): void
    {
        $listing = $this->draftOnElectric();

        self::assertSame('018f5b2a-0000-7000-8000-000000000001', $listing->id()->toString());
        self::assertSame(self::ANN, $listing->sellerId()->toString());
        self::assertSame(self::ELECTRIC, $listing->categoryId()->toString());
        self::assertSame('Fender Precision V', $listing->title()->toString());
        self::assertSame('Mint', $listing->description()->toString());
        self::assertSame(123450, $listing->price()->amountInMinorUnits());
        self::assertSame(Currency::EUR, $listing->price()->currency());
        self::assertSame(ListingStatus::Draft, $listing->status());
        self::assertTrue($listing->isSoldBy(SellerId::fromString(self::ANN)));
        self::assertFalse($listing->isSoldBy(SellerId::fromString(self::BOB)));
    }

    public function testDraftingOnAMissingCategoryIsRefused(): void
    {
        $this->expectException(UnknownCategory::class);

        $this->draftWithStatus(CategoryStatus::Missing);
    }

    public function testDraftingOnABranchIsRefused(): void
    {
        $this->expectException(CategoryIsNotALeaf::class);

        $this->draftWithStatus(CategoryStatus::Branch);
    }

    private function draftWithStatus(CategoryStatus $status): Listing
    {
        return Listing::draft(
            ListingId::fromString('018f5b2a-0000-7000-8000-000000000001'),
            SellerId::fromString(self::ANN),
            CategoryId::fromString(self::ELECTRIC),
            $status,
            ListingTitle::fromString('Fender Precision V'),
            ListingDescription::fromString(''),
            Price::fromMinorUnits(100, Currency::EUR),
        );
    }

    /**
     * @return array{string, string, string, int, Currency}
     */
    private function snapshot(Listing $listing): array
    {
        return [
            $listing->categoryId()->toString(),
            $listing->title()->toString(),
            $listing->description()->toString(),
            $listing->price()->amountInMinorUnits(),
            $listing->price()->currency(),
        ];
    }

    private function reviseBy(Listing $listing, string $by, string $category, CategoryStatus $status): void
    {
        $listing->revise(
            SellerId::fromString($by),
            CategoryId::fromString($category),
            $status,
            AttributeSchema::none(),
            ListingTitle::fromString('Changed title'),
            ListingDescription::fromString('Changed'),
            Price::fromMinorUnits(999, Currency::UAH),
            AttributeInput::fromArray([]),
        );
    }

    public function testAnotherSellerIsRefusedAndNothingChangesEvenWhenTheCategoryStatusIsAlsoBad(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->snapshot($listing);

        try {
            $this->reviseBy($listing, self::BOB, self::BASS, CategoryStatus::Branch);
            self::fail('Bob revised Ann\'s listing.');
        } catch (NotTheSeller) {
            self::assertSame($before, $this->snapshot($listing));
        }
    }

    public function testTheSellerCanReviseWithAnUnchangedCategoryEvenWhenItNowReadsAsABranch(): void
    {
        $listing = $this->draftOnElectric();

        $this->reviseBy($listing, self::ANN, self::ELECTRIC, CategoryStatus::Branch);

        self::assertSame([self::ELECTRIC, 'Changed title', 'Changed', 999, Currency::UAH], $this->snapshot($listing));
    }

    public function testTheSellerCanRefileToANewLeaf(): void
    {
        $listing = $this->draftOnElectric();

        $this->reviseBy($listing, self::ANN, self::BASS, CategoryStatus::Leaf);

        self::assertSame([self::BASS, 'Changed title', 'Changed', 999, Currency::UAH], $this->snapshot($listing));
    }

    public function testRefilingToAMissingCategoryIsRefusedAndNothingChanges(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->snapshot($listing);

        try {
            $this->reviseBy($listing, self::ANN, self::BASS, CategoryStatus::Missing);
            self::fail('Re-filed under a missing category.');
        } catch (UnknownCategory) {
            self::assertSame($before, $this->snapshot($listing));
        }
    }

    public function testRefilingToABranchIsRefusedAndNothingChanges(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->snapshot($listing);

        try {
            $this->reviseBy($listing, self::ANN, self::BASS, CategoryStatus::Branch);
            self::fail('Re-filed under a branch.');
        } catch (CategoryIsNotALeaf) {
            self::assertSame($before, $this->snapshot($listing));
        }
    }

    private function conditionRule(): AttributeRule
    {
        return AttributeRule::choice(AttributeId::fromString(self::CONDITION), [AttributeOptionId::fromString(self::NEW), AttributeOptionId::fromString(self::USED)]);
    }

    private function electricSchema(): AttributeSchema
    {
        return AttributeSchema::of(
            $this->conditionRule(),
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER), AttributeOptionId::fromString(self::GIBSON)]),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), 4, 12),
            AttributeRule::boolean(AttributeId::fromString(self::HAS_CASE)),
            AttributeRule::text(AttributeId::fromString(self::SERIAL)),
        );
    }

    private function bassSchema(): AttributeSchema
    {
        return AttributeSchema::of($this->conditionRule(), AttributeRule::number(AttributeId::fromString(self::BASS_STRINGS), null, null));
    }

    /**
     * @param array<string, string> $input
     */
    private function reviseWith(Listing $listing, string $by, string $category, CategoryStatus $status, AttributeSchema $schema, array $input): void
    {
        $listing->revise(
            SellerId::fromString($by),
            CategoryId::fromString($category),
            $status,
            $schema,
            ListingTitle::fromString('Changed title'),
            ListingDescription::fromString('Changed'),
            Price::fromMinorUnits(999, Currency::UAH),
            AttributeInput::fromArray($input),
        );
    }

    /**
     * @return array<string, string>
     */
    private function allFiveElectricValues(): array
    {
        return [self::CONDITION => self::USED, self::BRAND => self::FENDER, self::STRINGS => '6', self::HAS_CASE => 'yes', self::SERIAL => 'SN-1'];
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function valuesAsScalars(Listing $listing): array
    {
        $out = [];
        foreach ($listing->attributeValues()->toList() as $value) {
            $out[$value->attributeId()->toString()] = $value->optionId()?->toString() ?? $value->number() ?? $value->boolean() ?? $value->text();
        }

        return $out;
    }

    public function testADraftStartsWithNoAttributeValues(): void
    {
        self::assertTrue($this->draftOnElectric()->attributeValues()->isEmpty());
    }

    public function testRevisingWithValidValuesForAllFiveStoresExactlyThem(): void
    {
        $listing = $this->draftOnElectric();

        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), $this->allFiveElectricValues());

        self::assertSame(
            [self::CONDITION => self::USED, self::BRAND => self::FENDER, self::STRINGS => 6, self::HAS_CASE => true, self::SERIAL => 'SN-1'],
            $this->valuesAsScalars($listing),
        );
    }

    public function testRequiredAttributesLeftEmptyStillSave(): void
    {
        $listing = $this->draftOnElectric();

        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::CONDITION => '', self::BRAND => '']);

        self::assertTrue($listing->attributeValues()->isEmpty());
    }

    public function testBlankInputClearsAValueThatWasStored(): void
    {
        $listing = $this->draftOnElectric();
        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), $this->allFiveElectricValues());

        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::STRINGS => '8']);

        self::assertSame([self::STRINGS => 8], $this->valuesAsScalars($listing));
    }

    public function testAnInvalidValueIsRefusedAndEveryReaderIncludingTheValuesIsUnchanged(): void
    {
        $listing = $this->draftOnElectric();
        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), $this->allFiveElectricValues());
        $before = [$this->snapshot($listing), $this->valuesAsScalars($listing)];

        try {
            // Title, description, price and category are all valid and different: they must not leak in.
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::STRINGS => '13']);
            self::fail('An out-of-range value was accepted.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::OutOfRange, $e->reason());
            self::assertSame($before, [$this->snapshot($listing), $this->valuesAsScalars($listing)]);
        }
    }

    public function testAnInvalidValueOnARefileLeavesTheCategoryUntouchedToo(): void
    {
        $listing = $this->draftOnElectric();
        $before = [$this->snapshot($listing), $this->valuesAsScalars($listing)];

        try {
            $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Leaf, $this->bassSchema(), [self::CONDITION => self::FENDER]);
            self::fail('A foreign option was accepted for condition.');
        } catch (InvalidAttributeValue) {
            self::assertSame($before, [$this->snapshot($listing), $this->valuesAsScalars($listing)]);
        }
    }

    public function testTheSellerCheckComesBeforeAnyValueIsLookedAt(): void
    {
        $listing = $this->draftOnElectric();

        $this->expectException(NotTheSeller::class);

        $this->reviseWith($listing, self::BOB, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::STRINGS => '13']);
    }

    public function testAnInputForAnAttributeNotInForceIsRefusedOnAnUnchangedCategory(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->snapshot($listing);

        try {
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::BASS_STRINGS => '4']);
            self::fail('An attribute of another category was dropped instead of refused.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::NotInForce, $e->reason());
            self::assertSame(self::BASS_STRINGS, $e->attributeId()?->toString());
            self::assertSame($before, $this->snapshot($listing));
            self::assertTrue($listing->attributeValues()->isEmpty());
        }
    }

    public function testRefilingKeepsSharedValuesAndDropsTheRestWithoutRefusing(): void
    {
        $listing = $this->draftOnElectric();

        $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Leaf, $this->bassSchema(), [
            self::CONDITION => self::NEW,
            self::BRAND => self::FENDER,
            self::STRINGS => '6',
        ]);

        self::assertSame(self::BASS, $listing->categoryId()->toString());
        self::assertSame([self::CONDITION => self::NEW], $this->valuesAsScalars($listing));
    }

    public function testRefilingStillChecksTheValuesItKeeps(): void
    {
        $listing = $this->draftOnElectric();

        $this->expectException(InvalidAttributeValue::class);

        $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Leaf, $this->bassSchema(), [self::CONDITION => self::FENDER, self::BRAND => self::FENDER]);
    }

    public function testRefilingToABranchOrAMissingCategoryIsRefusedBeforeAnyValueIsLookedAt(): void
    {
        foreach ([[CategoryStatus::Branch, CategoryIsNotALeaf::class], [CategoryStatus::Missing, UnknownCategory::class]] as [$status, $expected]) {
            $listing = $this->draftOnElectric();

            try {
                // The value is also invalid; the category refusal must win.
                $this->reviseWith($listing, self::ANN, self::BASS, $status, $this->bassSchema(), [self::CONDITION => 'not-an-option']);
                self::fail('Re-filed under '.$status->name);
            } catch (\DomainException $e) {
                self::assertInstanceOf($expected, $e);
            }
        }
    }

    public function testListingRecordsNoDomainEvents(): void
    {
        self::assertSame([], class_uses(Listing::class));
        self::assertFalse((new \ReflectionClass(Listing::class))->hasMethod('releaseEvents'));
    }

    public function testThePublicMethodsAreExactlyTheAllowList(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Listing::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(
            ['attributeValues', 'categoryId', 'description', 'draft', 'id', 'isSoldBy', 'price', 'revise', 'sellerId', 'status', 'title'],
            $methods,
        );
    }
}
