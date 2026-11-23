<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\AttributeId;
use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\FacetAttribute;
use App\Domain\Search\ValueObject\FacetBucket;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FacetKind;
use App\Domain\Search\ValueObject\FacetSchema;
use App\Domain\Search\ValueObject\FilterRequest;
use PHPUnit\Framework\TestCase;

/**
 * search-browse AC-4: `Facet::of()` — bucket order, zero-count rules, a selected bucket kept at 0
 * and labelled from the facet schema, a facet with no bucket omitted.
 */
final class FacetTest extends TestCase
{
    private const string BRAND = '018f5b2a-0000-7000-8000-0000000000a1';
    private const string HAS_CASE = '018f5b2a-0000-7000-8000-0000000000a3';
    private const string CATEGORY = '018f5b2a-0000-7000-8000-0000000000c1';

    private static function option(int $n): string
    {
        return \sprintf('018f5b2a-0000-7000-8000-%012d', 100 + $n);
    }

    private function brand(): FacetAttribute
    {
        return new FacetAttribute(AttributeId::fromString(self::BRAND), FacetKind::Choice, 'Brand');
    }

    private function hasCase(): FacetAttribute
    {
        return new FacetAttribute(AttributeId::fromString(self::HAS_CASE), FacetKind::Boolean, 'Has case');
    }

    /**
     * A query selecting `$brandOptions` of Brand (each labelled in the schema) and `$hasCase` of Has case.
     *
     * @param array<string, string> $brandOptions option id => schema label
     * @param list<string>          $hasCase      `yes` / `no`
     */
    private function query(array $brandOptions = [], array $hasCase = []): FacetedQuery
    {
        $brand = AttributeId::fromString(self::BRAND);
        $options = [];
        foreach ($brandOptions as $id => $label) {
            $options[$id] = ['attribute' => $brand, 'label' => $label];
        }
        $filters = [];
        if ([] !== $brandOptions) {
            $filters[self::BRAND] = array_keys($brandOptions);
        }
        if ([] !== $hasCase) {
            $filters[self::HAS_CASE] = $hasCase;
        }

        return FacetedQuery::resolve(
            CategoryId::fromString(self::CATEGORY),
            FilterRequest::parse($filters, [], ['currency' => null, 'min' => null, 'max' => null], null),
            FacetSchema::of([$this->brand(), $this->hasCase()], $options),
            1,
        );
    }

    /**
     * @return list<array{string, string, int, bool}> value, label, count, selected
     */
    private function described(?Facet $facet): array
    {
        self::assertNotNull($facet, 'The facet was omitted.');

        return array_map(static fn (FacetBucket $b): array => [$b->value(), $b->label(), $b->count(), $b->selected()], $facet->buckets());
    }

    public function testChoiceBucketsAreOrderedByLabelIgnoringCase(): void
    {
        $counts = [self::option(1) => 1, self::option(2) => 2, self::option(3) => 3, self::option(4) => 4];
        $labels = [self::option(1) => 'banana', self::option(2) => 'Apple', self::option(3) => 'cherry', self::option(4) => 'Zebra'];

        $facet = Facet::of($this->brand(), $counts, $labels, $this->query());

        self::assertSame(['Apple', 'banana', 'cherry', 'Zebra'], array_map(static fn (array $b): string => $b[1], $this->described($facet)));
    }

    public function testBucketsWithTheSameLabelAreOrderedById(): void
    {
        $counts = [self::option(9) => 1, self::option(2) => 1, self::option(5) => 1];
        $labels = [self::option(9) => 'Same', self::option(2) => 'same', self::option(5) => 'SAME'];

        $facet = Facet::of($this->brand(), $counts, $labels, $this->query());

        self::assertSame([self::option(2), self::option(5), self::option(9)], array_map(static fn (array $b): string => $b[0], $this->described($facet)));
    }

    public function testOrderingFoldsNonAsciiCaseToo(): void
    {
        $counts = [self::option(1) => 1, self::option(2) => 1];
        $labels = [self::option(1) => 'Édith', self::option(2) => 'édgar'];

        $facet = Facet::of($this->brand(), $counts, $labels, $this->query());

        // Unfolded, "É" (U+00C9) sorts before "é" (U+00E9), which would put Édith first.
        self::assertSame(['édgar', 'Édith'], array_map(static fn (array $b): string => $b[1], $this->described($facet)));
    }

    public function testBooleanBucketsAreYesThenNoWhateverOrderTheCountsCameIn(): void
    {
        $facet = Facet::of($this->hasCase(), ['no' => 1, 'yes' => 2], [], $this->query());

        self::assertSame([['yes', 'Yes', 2, false], ['no', 'No', 1, false]], $this->described($facet));
    }

    public function testAnUnselectedChoiceBucketWithCountZeroIsOmitted(): void
    {
        $facet = Facet::of($this->brand(), [self::option(1) => 0, self::option(2) => 3], [self::option(1) => 'Gibson', self::option(2) => 'Fender'], $this->query());

        self::assertSame([[self::option(2), 'Fender', 3, false]], $this->described($facet));
    }

    public function testAnUnselectedBooleanBucketWithCountZeroIsOmitted(): void
    {
        $facet = Facet::of($this->hasCase(), ['yes' => 2, 'no' => 0], [], $this->query());

        self::assertSame([['yes', 'Yes', 2, false]], $this->described($facet));
    }

    public function testASelectedChoiceBucketIsKeptAtCountZeroAndLabelledFromTheSchema(): void
    {
        $query = $this->query([self::option(1) => 'Gibson']);

        // Gibson is absent from the counts altogether (no listing holds it under the other selections).
        $facet = Facet::of($this->brand(), [self::option(2) => 3], [self::option(2) => 'Fender'], $query);

        self::assertSame(
            [[self::option(2), 'Fender', 3, false], [self::option(1), 'Gibson', 0, true]],
            $this->described($facet),
        );
    }

    public function testASelectedChoiceBucketReportedWithCountZeroIsKeptToo(): void
    {
        $query = $this->query([self::option(1) => 'Gibson']);

        $facet = Facet::of($this->brand(), [self::option(1) => 0], [self::option(1) => 'Gibson'], $query);

        self::assertSame([[self::option(1), 'Gibson', 0, true]], $this->described($facet));
    }

    public function testASelectedBucketWithACountIsMarkedSelected(): void
    {
        $query = $this->query([self::option(1) => 'Gibson']);

        $facet = Facet::of($this->brand(), [self::option(1) => 1, self::option(2) => 3], [self::option(1) => 'Gibson', self::option(2) => 'Fender'], $query);

        self::assertSame(
            [[self::option(2), 'Fender', 3, false], [self::option(1), 'Gibson', 1, true]],
            $this->described($facet),
        );
    }

    public function testASelectedBooleanBucketIsKeptAtCountZero(): void
    {
        $facet = Facet::of($this->hasCase(), ['yes' => 2], [], $this->query(hasCase: ['no']));

        self::assertSame([['yes', 'Yes', 2, false], ['no', 'No', 0, true]], $this->described($facet));
    }

    public function testAFacetWithNoBucketAndNoSelectionIsOmitted(): void
    {
        self::assertNull(Facet::of($this->brand(), [], [], $this->query()));
        self::assertNull(Facet::of($this->brand(), [self::option(1) => 0], [self::option(1) => 'Fender'], $this->query()));
        self::assertNull(Facet::of($this->hasCase(), ['yes' => 0, 'no' => 0], [], $this->query()));
    }

    public function testAFacetWithASelectionIsKeptEvenWhenEveryCountIsZero(): void
    {
        $facet = Facet::of($this->brand(), [], [], $this->query([self::option(1) => 'Gibson']));

        self::assertSame([[self::option(1), 'Gibson', 0, true]], $this->described($facet));
    }

    public function testTheFacetExposesItsAttribute(): void
    {
        $facet = Facet::of($this->brand(), [self::option(1) => 1], [self::option(1) => 'Fender'], $this->query());

        self::assertNotNull($facet);
        self::assertSame('Brand', $facet->attribute()->label());
        self::assertSame(self::BRAND, $facet->attribute()->attributeId()->toString());
    }
}
