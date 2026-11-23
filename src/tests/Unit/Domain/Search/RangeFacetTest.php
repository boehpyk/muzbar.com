<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\AttributeId;
use App\Domain\Search\ValueObject\CategoryId;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\FacetAttribute;
use App\Domain\Search\ValueObject\FacetedQuery;
use App\Domain\Search\ValueObject\FacetKind;
use App\Domain\Search\ValueObject\FacetSchema;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\RangeFacet;
use PHPUnit\Framework\TestCase;

/**
 * search-range-facets AC-6: `RangeFacet::of()` — omitted at count 0 unless selected, kept at 0 with a
 * selection (observed range null), otherwise carrying unit, count, observed range and selected range.
 * And `Facet::of()` refuses a number attribute.
 */
final class RangeFacetTest extends TestCase
{
    private const string STRINGS = '018f5b2a-0000-7000-8000-0000000000a5';
    private const string BRAND = '018f5b2a-0000-7000-8000-0000000000a1';
    private const string CATEGORY = '018f5b2a-0000-7000-8000-0000000000c1';

    private function strings(): FacetAttribute
    {
        return new FacetAttribute(AttributeId::fromString(self::STRINGS), FacetKind::Number, 'Strings', 'pcs');
    }

    private function query(?string $min = null, ?string $max = null): FacetedQuery
    {
        return FacetedQuery::resolve(
            CategoryId::fromString(self::CATEGORY),
            FilterRequest::parse([], [self::STRINGS => ['min' => $min, 'max' => $max]], ['currency' => null, 'min' => null, 'max' => null]),
            FacetSchema::of([$this->strings()], []),
            1,
        );
    }

    public function testCountZeroAndNoSelectionIsOmitted(): void
    {
        self::assertNull(RangeFacet::of($this->strings(), 0, null, null, $this->query()));
    }

    public function testCountZeroWithASelectionIsKeptWithNoObservedRange(): void
    {
        $facet = RangeFacet::of($this->strings(), 0, null, null, $this->query('7', null));

        if (null === $facet) {
            self::fail('A selected number facet was omitted, so its range could not be cleared.');
        }
        self::assertSame(0, $facet->count());
        self::assertNull($facet->observed());
        self::assertSame([7, null], [$facet->selected()?->min(), $facet->selected()?->max()]);
    }

    public function testAFacetWithValuesCarriesUnitCountObservedRangeAndNoSelection(): void
    {
        $facet = RangeFacet::of($this->strings(), 3, 6, 12, $this->query());

        if (null === $facet) {
            self::fail('A number facet with values was omitted.');
        }
        self::assertSame('Strings', $facet->attribute()->label());
        self::assertSame('pcs', $facet->attribute()->unit());
        self::assertSame(3, $facet->count());
        self::assertSame([6, 12], [$facet->observed()?->min(), $facet->observed()?->max()]);
        self::assertNull($facet->selected());
    }

    public function testASelectedFacetCarriesTheSelectedRangeBesideTheObservedOne(): void
    {
        $facet = RangeFacet::of($this->strings(), 3, 6, 12, $this->query('20', '4'));

        self::assertSame([4, 20], [$facet?->selected()?->min(), $facet?->selected()?->max()]);
        self::assertSame([6, 12], [$facet?->observed()?->min(), $facet?->observed()?->max()]);
    }

    public function testANonNumberAttributeIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        RangeFacet::of(new FacetAttribute(AttributeId::fromString(self::BRAND), FacetKind::Choice, 'Brand'), 1, null, null, $this->query());
    }

    public function testFacetOfRefusesANumberAttribute(): void
    {
        $this->expectException(\LogicException::class);

        Facet::of($this->strings(), [], [], $this->query());
    }
}
