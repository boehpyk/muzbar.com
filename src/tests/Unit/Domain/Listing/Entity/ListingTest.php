<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\Entity;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Event\ListingPublished;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\Exception\ListingIncomplete;
use App\Domain\Listing\Exception\ListingNotPublished;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\PublishedListingCannotBeRefiled;
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
use App\Domain\Shared\Event\RecordsEvents;
use PHPUnit\Framework\TestCase;

/** listing-publish AC-1…AC-4 and AC-6, listing-attribute-values AC-3/AC-4, plus listing-draft's AC-4, AC-5, AC-6. */
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
        return AttributeRule::choice(AttributeId::fromString(self::CONDITION), [AttributeOptionId::fromString(self::NEW), AttributeOptionId::fromString(self::USED)], false);
    }

    private function electricSchema(): AttributeSchema
    {
        return AttributeSchema::of(
            $this->conditionRule(),
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER), AttributeOptionId::fromString(self::GIBSON)], false),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), 4, 12, false),
            AttributeRule::boolean(AttributeId::fromString(self::HAS_CASE), false),
            AttributeRule::text(AttributeId::fromString(self::SERIAL), false),
        );
    }

    private function bassSchema(): AttributeSchema
    {
        return AttributeSchema::of($this->conditionRule(), AttributeRule::number(AttributeId::fromString(self::BASS_STRINGS), null, null, false));
    }

    /**
     * @param array<string, string> $input
     */
    private function reviseWith(Listing $listing, string $by, string $category, CategoryStatus $status, AttributeSchema $schema, array $input, string $title = 'Changed title', string $description = 'Changed', int $minorUnits = 999): void
    {
        $listing->revise(
            SellerId::fromString($by),
            CategoryId::fromString($category),
            $status,
            $schema,
            ListingTitle::fromString($title),
            ListingDescription::fromString($description),
            Price::fromMinorUnits($minorUnits, Currency::UAH),
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
            // Title, description and price are all valid and different from what is held: they must not leak in.
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::STRINGS => '13'], 'Never saved', 'Never saved either', 1);
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

    private const string NEW_REQUIRED = '018f5b2a-0000-7000-8000-00000000a0f0';

    private function at(int $plusSeconds = 0): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'))->modify(\sprintf('+%d seconds', $plusSeconds));
    }

    /** Electric's schema with condition and brand **required** — the spec's fixture schema. */
    private function requiredElectricSchema(?int $stringsMin = 4, ?int $stringsMax = 12): AttributeSchema
    {
        return AttributeSchema::of(
            AttributeRule::choice(AttributeId::fromString(self::CONDITION), [AttributeOptionId::fromString(self::NEW), AttributeOptionId::fromString(self::USED)], true),
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER), AttributeOptionId::fromString(self::GIBSON)], true),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), $stringsMin, $stringsMax, false),
            AttributeRule::boolean(AttributeId::fromString(self::HAS_CASE), false),
            AttributeRule::text(AttributeId::fromString(self::SERIAL), false),
        );
    }

    private function requiredBassSchema(): AttributeSchema
    {
        return AttributeSchema::of(
            AttributeRule::choice(AttributeId::fromString(self::CONDITION), [AttributeOptionId::fromString(self::NEW), AttributeOptionId::fromString(self::USED)], true),
            AttributeRule::number(AttributeId::fromString(self::BASS_STRINGS), null, null, false),
        );
    }

    /** "Complete D": condition = Used, brand = Fender, on Electric, still a draft. */
    private function completeDraft(): Listing
    {
        $listing = $this->draftOnElectric();
        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->requiredElectricSchema(), [self::CONDITION => self::USED, self::BRAND => self::FENDER]);

        return $listing;
    }

    private function publishedD(): Listing
    {
        $listing = $this->completeDraft();
        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(), $this->at());
        $listing->releaseEvents();

        return $listing;
    }

    private function withdrawnD(): Listing
    {
        $listing = $this->publishedD();
        $listing->withdraw(SellerId::fromString(self::ANN));

        return $listing;
    }

    /**
     * @return array{ListingStatus, ?string, list<string>, array<string, int|string|bool|null>, array{string, string, string, int, Currency}}
     */
    private function everything(Listing $listing): array
    {
        return [
            $listing->status(),
            $listing->publishedAt()?->format('c'),
            array_map(static fn (object $e): string => $e::class, $listing->releaseEvents()),
            $this->valuesAsScalars($listing),
            $this->snapshot($listing),
        ];
    }

    public function testPublishingACompleteDraftMakesItPublicStampsTheClockAndRecordsOneEvent(): void
    {
        $listing = $this->completeDraft();

        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(), $this->at());

        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertEquals($this->at(), $listing->publishedAt());

        $events = $listing->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ListingPublished::class, $events[0]);
        self::assertSame('018f5b2a-0000-7000-8000-000000000001', $events[0]->listingId()->toString());
        self::assertSame(self::ANN, $events[0]->sellerId()->toString());
        self::assertSame(self::ELECTRIC, $events[0]->categoryId()->toString());
        self::assertEquals($this->at(), $events[0]->publishedAt());
        self::assertEquals($this->at(), $events[0]->occurredAt());
    }

    public function testAFreshDraftHasNoPublishedAtAndRecordsNoEvent(): void
    {
        $listing = $this->draftOnElectric();

        self::assertNull($listing->publishedAt());
        self::assertSame([], $listing->releaseEvents());
    }

    public function testListingPublishedHoldsOnlyValueObjectsAndAnInstant(): void
    {
        $allowed = [ListingId::class, SellerId::class, CategoryId::class, \DateTimeImmutable::class];
        $types = array_map(
            static fn (\ReflectionProperty $p): string => (string) $p->getType(),
            (new \ReflectionClass(ListingPublished::class))->getProperties(),
        );
        sort($types);
        sort($allowed);

        self::assertSame($allowed, $types);
    }

    public function testAnotherSellerCannotPublishEvenWhenTheListingIsAlsoIncomplete(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->everything($listing);

        try {
            $listing->publish(SellerId::fromString(self::BOB), CategoryStatus::Branch, $this->requiredElectricSchema(), $this->at());
            self::fail('Bob published Ann\'s listing.');
        } catch (NotTheSeller) {
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testPublishingOnABranchOrAMissingCategoryIsRefusedEvenThoughTheCategoryIsUnchanged(): void
    {
        foreach ([[CategoryStatus::Branch, CategoryIsNotALeaf::class], [CategoryStatus::Missing, UnknownCategory::class]] as [$status, $expected]) {
            $listing = $this->completeDraft();
            $before = $this->everything($listing);

            try {
                $listing->publish(SellerId::fromString(self::ANN), $status, $this->requiredElectricSchema(), $this->at());
                self::fail('Published under '.$status->name);
            } catch (\DomainException $e) {
                self::assertInstanceOf($expected, $e);
                self::assertSame($before, $this->everything($listing));
            }
        }
    }

    public function testPublishingReReadsAStoredValueAgainstTheCurrentBounds(): void
    {
        $listing = $this->completeDraft();
        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->requiredElectricSchema(), [self::CONDITION => self::USED, self::BRAND => self::FENDER, self::STRINGS => '6']);
        $before = $this->everything($listing);

        try {
            // The schema has since been narrowed to 7…12; the stored 6 now does not fit.
            $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(7, 12), $this->at());
            self::fail('A stored value outside the current bounds was published.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::OutOfRange, $e->reason());
            self::assertSame(self::STRINGS, $e->attributeId()?->toString());
            self::assertSame(7, $e->min());
            self::assertSame(12, $e->max());
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testPublishingAnIncompleteDraftNamesEveryMissingAttributeInOneException(): void
    {
        $listing = $this->draftOnElectric();
        $before = $this->everything($listing);

        try {
            $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(), $this->at());
            self::fail('An incomplete listing was published.');
        } catch (ListingIncomplete $e) {
            self::assertSame([self::CONDITION, self::BRAND], array_map(static fn (AttributeId $id): string => $id->toString(), $e->attributeIds()));
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testPublishRefusalsAreOrderedSellerThenCategoryThenValuesThenCompleteness(): void
    {
        // A draft on a Branch with an empty brand: the category refusal wins over L-13.
        $listing = $this->draftOnElectric();

        $this->expectException(CategoryIsNotALeaf::class);

        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Branch, $this->requiredElectricSchema(), $this->at());
    }

    public function testAnInvalidStoredValueIsReportedBeforeAMissingRequiredOne(): void
    {
        $listing = $this->draftOnElectric();
        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->electricSchema(), [self::STRINGS => '6']);

        // brand and condition are empty (L-13) *and* strings = 6 is out of 7…12 (L-9): L-9 first.
        $this->expectException(InvalidAttributeValue::class);

        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(7, 12), $this->at());
    }

    public function testPublishingAPublishedListingChangesNothingKeepsPublishedAtAndRecordsNothing(): void
    {
        $listing = $this->publishedD();
        $before = $this->everything($listing);

        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(), $this->at(60));

        self::assertSame($before, $this->everything($listing));
        self::assertEquals($this->at(), $listing->publishedAt());
    }

    public function testWithdrawingAPublishedListingKeepsPublishedAtAndRecordsNoEvent(): void
    {
        $listing = $this->publishedD();

        $listing->withdraw(SellerId::fromString(self::ANN));

        self::assertSame(ListingStatus::Withdrawn, $listing->status());
        self::assertEquals($this->at(), $listing->publishedAt());
        self::assertSame([], $listing->releaseEvents());
    }

    public function testWithdrawingAWithdrawnListingChangesNothing(): void
    {
        $listing = $this->withdrawnD();
        $before = $this->everything($listing);

        $listing->withdraw(SellerId::fromString(self::ANN));

        self::assertSame($before, $this->everything($listing));
    }

    public function testWithdrawingADraftIsRefused(): void
    {
        $listing = $this->completeDraft();

        try {
            $listing->withdraw(SellerId::fromString(self::ANN));
            self::fail('A draft was withdrawn.');
        } catch (ListingNotPublished) {
            self::assertSame(ListingStatus::Draft, $listing->status());
        }
    }

    public function testAnotherSellerCannotWithdraw(): void
    {
        $listing = $this->publishedD();

        try {
            $listing->withdraw(SellerId::fromString(self::BOB));
            self::fail('Bob withdrew Ann\'s listing.');
        } catch (NotTheSeller) {
            self::assertSame(ListingStatus::Published, $listing->status());
        }
    }

    public function testWithdrawByAnotherSellerIsRefusedAsNotTheSellerEvenForADraft(): void
    {
        $this->expectException(NotTheSeller::class);

        $this->completeDraft()->withdraw(SellerId::fromString(self::BOB));
    }

    public function testPublishingAgainAfterAWithdrawMovesPublishedAtForwardAndRecordsOneEvent(): void
    {
        $listing = $this->withdrawnD();

        $listing->publish(SellerId::fromString(self::ANN), CategoryStatus::Leaf, $this->requiredElectricSchema(), $this->at(60));

        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertEquals($this->at(60), $listing->publishedAt());
        $events = $listing->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ListingPublished::class, $events[0]);
        self::assertEquals($this->at(60), $events[0]->publishedAt());
    }

    public function testADraftAndAWithdrawnListingSaveWithRequiredDetailsEmpty(): void
    {
        foreach ([$this->draftOnElectric(), $this->withdrawnD()] as $listing) {
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->requiredElectricSchema(), [self::CONDITION => '', self::BRAND => '']);

            self::assertTrue($listing->attributeValues()->isEmpty(), $listing->status()->name);
        }
    }

    public function testRevisingAPublishedListingThatKeepsItsRequiredDetailsSucceedsAndStaysPublished(): void
    {
        $listing = $this->publishedD();

        $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->requiredElectricSchema(), [self::CONDITION => self::NEW, self::BRAND => self::GIBSON], 'New title');

        self::assertSame(ListingStatus::Published, $listing->status());
        self::assertSame('New title', $listing->title()->toString());
        self::assertSame([self::CONDITION => self::NEW, self::BRAND => self::GIBSON], $this->valuesAsScalars($listing));
        self::assertSame([], $listing->releaseEvents());
    }

    public function testRevisingAPublishedListingByClearingARequiredDetailIsRefusedAndNothingChanges(): void
    {
        $listing = $this->publishedD();
        $before = $this->everything($listing);

        try {
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $this->requiredElectricSchema(), [self::CONDITION => self::USED, self::BRAND => ''], 'Never saved');
            self::fail('A published listing lost a required detail.');
        } catch (ListingIncomplete $e) {
            self::assertSame([self::BRAND], array_map(static fn (AttributeId $id): string => $id->toString(), $e->attributeIds()));
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testRevisingAPublishedListingAgainstASchemaWithANewRequiredAttributeNamesIt(): void
    {
        $listing = $this->publishedD();
        $before = $this->everything($listing);
        $schema = AttributeSchema::of(
            AttributeRule::choice(AttributeId::fromString(self::CONDITION), [AttributeOptionId::fromString(self::NEW), AttributeOptionId::fromString(self::USED)], true),
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER), AttributeOptionId::fromString(self::GIBSON)], true),
            AttributeRule::text(AttributeId::fromString(self::NEW_REQUIRED), true),
        );

        try {
            // The input has no field for the new attribute — absent, not blank.
            $this->reviseWith($listing, self::ANN, self::ELECTRIC, CategoryStatus::Leaf, $schema, [self::CONDITION => self::USED, self::BRAND => self::FENDER]);
            self::fail('A new required attribute was ignored.');
        } catch (ListingIncomplete $e) {
            self::assertSame([self::NEW_REQUIRED], array_map(static fn (AttributeId $id): string => $id->toString(), $e->attributeIds()));
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testAPublishedListingCannotBeRefiledEvenToALeaf(): void
    {
        $listing = $this->publishedD();
        $before = $this->everything($listing);

        try {
            $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Leaf, $this->requiredBassSchema(), [self::CONDITION => self::USED]);
            self::fail('A published listing was re-filed.');
        } catch (PublishedListingCannotBeRefiled) {
            self::assertSame($before, $this->everything($listing));
        }
    }

    public function testTheRefileRefusalComesBeforeTheCategoryCheckEvenWhenTheTargetIsMissing(): void
    {
        $listing = $this->publishedD();

        $this->expectException(PublishedListingCannotBeRefiled::class);

        $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Missing, $this->requiredBassSchema(), [self::CONDITION => self::USED]);
    }

    public function testAWithdrawnListingCanBeRefiledAndLosesWhatDoesNotFollow(): void
    {
        $listing = $this->withdrawnD();

        $this->reviseWith($listing, self::ANN, self::BASS, CategoryStatus::Leaf, $this->requiredBassSchema(), [self::CONDITION => self::USED, self::BRAND => self::FENDER]);

        self::assertSame(self::BASS, $listing->categoryId()->toString());
        self::assertSame(ListingStatus::Withdrawn, $listing->status());
        self::assertSame([self::CONDITION => self::USED], $this->valuesAsScalars($listing));
    }

    public function testListingRecordsDomainEvents(): void
    {
        // Inverted on purpose by listing-publish (AC-6): `publish()` records `ListingPublished`.
        self::assertSame([RecordsEvents::class => RecordsEvents::class], class_uses(Listing::class));
    }

    public function testThePublicMethodsAreExactlyTheAllowList(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Listing::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(
            ['attributeValues', 'categoryId', 'description', 'draft', 'id', 'isSoldBy', 'price', 'publish', 'publishedAt', 'releaseEvents', 'revise', 'sellerId', 'status', 'title', 'withdraw'],
            $methods,
        );
    }
}
