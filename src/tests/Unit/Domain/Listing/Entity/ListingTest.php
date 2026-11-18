<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\Entity;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\CategoryIsNotALeaf;
use App\Domain\Listing\Exception\NotTheSeller;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\ValueObject\AttributeInput;
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

/** AC-4, AC-5, AC-6. */
final class ListingTest extends TestCase
{
    private const string ANN = '018f5b2a-0000-7000-8000-0000000000a1';
    private const string BOB = '018f5b2a-0000-7000-8000-0000000000b2';
    private const string ELECTRIC = '018f5b2a-0000-7000-8000-0000000000e1';
    private const string BASS = '018f5b2a-0000-7000-8000-0000000000e2';

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
