<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Search\Handler\BrowseListingsHandler;
use App\Application\Search\Query\BrowseListings;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\RangeFacet;
use App\Domain\Search\ValueObject\SearchResult;
use App\Tests\Support\BuildsTheRangeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * search-range-facets AC-8 … AC-18, through `BrowseListingsHandler` (S-10's clauses live in
 * `facetSchemaFor()` and `resolve()`, S-11/S-13's in the counts). Real database, DAMA. Fixture:
 * `BuildsTheRangeFixture` (feature-spec §B). Sits beside `BrowseListingsTest`, whose AC numbers
 * belong to `search-browse`.
 */
final class BrowseRangeFacetsTest extends KernelTestCase
{
    use BuildsTheRangeFixture;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @param array<string, array{min: ?string, max: ?string}>          $ranges
     * @param array{currency: ?string, min: ?string, max: ?string}|null $price
     * @param array<string, list<string>>                               $filters
     */
    private function browse(?Category $category, array $ranges = [], ?array $price = null, array $filters = []): SearchResult
    {
        $handler = self::getContainer()->get(BrowseListingsHandler::class);
        self::assertInstanceOf(BrowseListingsHandler::class, $handler);

        return $handler(new BrowseListings($category?->id()->toString(), $filters, $ranges, $price ?? ['currency' => null, 'min' => null, 'max' => null], null, 1));
    }

    /**
     * @return array<string, array{min: ?string, max: ?string}>
     */
    private function strings(?string $min, ?string $max): array
    {
        return [$this->strings->id()->toString() => ['min' => $min, 'max' => $max]];
    }

    private function totalAt(Category $category, ?string $min, ?string $max): int
    {
        return $this->browse($category, $this->strings($min, $max))->total();
    }

    private function rangeFacet(SearchResult $result, Attribute $attribute): ?RangeFacet
    {
        foreach ($result->facets() as $facet) {
            if ($facet instanceof RangeFacet && $facet->attribute()->attributeId()->toString() === $attribute->id()->toString()) {
                return $facet;
            }
        }

        return null;
    }

    /**
     * @return array{int, int}|null the observed range, or null when the facet is not shown
     */
    private function observed(SearchResult $result, Attribute $attribute): ?array
    {
        $facet = $this->rangeFacet($result, $attribute);
        $observed = $facet?->observed();

        return null === $observed ? null : [$observed->min() ?? self::fail('open observed range'), $observed->max() ?? self::fail('open observed range')];
    }

    /**
     * @return list<string>
     */
    private function facetLabels(SearchResult $result): array
    {
        return array_map(static fn (Facet|RangeFacet $facet): string => $facet->attribute()->label(), $result->facets());
    }

    /**
     * @return list<string>
     */
    private function hitIds(SearchResult $result): array
    {
        return array_map(static fn ($hit): string => $hit->id(), $result->hits());
    }

    /**
     * @return array<string, int>
     */
    private function brandBuckets(SearchResult $result): array
    {
        $counts = [];
        foreach ($result->facets() as $facet) {
            if ($facet instanceof Facet && 'Brand' === $facet->attribute()->label()) {
                foreach ($facet->buckets() as $bucket) {
                    $counts[$bucket->label()] = $bucket->count();
                }
            }
        }

        return $counts;
    }

    // ---------------------------------------------------------------- AC-8

    public function testARangeCountsOnlyPublishedListingsInTheBrowsedSubtree(): void
    {
        $this->buildTheRangeFixture();

        $result = $this->browse($this->electric, $this->strings('1', null));

        self::assertSame(3, $result->total());
        self::assertEqualsCanonicalizing([$this->listingN6a, $this->listingN6b, $this->listingN12], $this->hitIds($result));
        // Controls: the draft and the withdrawn listing do hold Strings values inside the range.
        self::assertSame(2, $this->rangeDb()->fetchOne("SELECT count(*) FROM listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE v.attribute_id = ? AND l.status <> 'published'", [$this->strings->id()->toString()]));
        self::assertNotContains($this->listingBb, $this->hitIds($result));
    }

    // ---------------------------------------------------------------- AC-9

    public function testANumberAttributeIsAFacetWithUnitCountAndObservedRangeOnlyInItsLineage(): void
    {
        $this->buildTheRangeFixture();

        $electric = $this->browse($this->electric);
        $facet = $this->rangeFacet($electric, $this->strings);

        self::assertNotNull($facet);
        self::assertSame('pcs', $facet->attribute()->unit());
        self::assertSame(3, $facet->count());
        self::assertSame([6, 12], $this->observed($electric, $this->strings));
        self::assertNotContains('Serial', $this->facetLabels($electric));

        $guitars = $this->browse($this->guitars);
        self::assertNull($this->rangeFacet($guitars, $this->strings));
        $dropped = $this->browse($this->guitars, $this->strings('6', '6'));
        self::assertSame([], $dropped->query()->selections());
        self::assertSame($guitars->total(), $dropped->total());
    }

    // ---------------------------------------------------------------- AC-10

    public function testARangeIsInclusiveAndMayBeHalfOpen(): void
    {
        $this->buildTheRangeFixture();

        self::assertSame(2, $this->totalAt($this->electric, '6', '6'));
        self::assertSame(1, $this->totalAt($this->electric, '7', null));
        self::assertSame(2, $this->totalAt($this->electric, null, '6'));
        self::assertSame(3, $this->totalAt($this->electric, '6', '12'));
        self::assertSame(0, $this->totalAt($this->electric, '13', null));
    }

    // ---------------------------------------------------------------- AC-11

    public function testAListingWithNoValueIsInNoRangeHoweverWide(): void
    {
        $this->buildTheRangeFixture();

        self::assertContains($this->listingN0, $this->hitIds($this->browse($this->electric)));
        foreach ([['-9999999', null], [null, '9999999'], ['-9999999', '9999999']] as [$min, $max]) {
            self::assertNotContains($this->listingN0, $this->hitIds($this->browse($this->electric, $this->strings($min, $max))));
        }
        self::assertSame(4, $this->browse($this->electric)->total());
        self::assertSame(3, $this->totalAt($this->electric, '-9999999', '9999999'));
    }

    // ---------------------------------------------------------------- AC-12

    public function testARangeFacetsStatsIgnoreItsOwnRangeButApplyEveryOtherSelection(): void
    {
        $this->buildTheRangeFixture();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $brand = $this->brand->id()->toString();

        $result = $this->browse($this->electric, $this->strings('7', null), null, [$brand => [$fender]]);

        self::assertSame(1, $result->total());
        self::assertSame([$this->listingN12], $this->hitIds($result));
        $facet = $this->rangeFacet($result, $this->strings);
        self::assertNotNull($facet);
        self::assertSame(2, $facet->count(), 'Own range ignored, Brand = Fender applied (N6a, N12).');
        self::assertSame([6, 12], $this->observed($result, $this->strings));
        self::assertSame(7, $facet->selected()?->min());
        // Brand's buckets apply the Strings range (N12 only) and ignore Brand's own selection.
        self::assertSame(['Fender' => 1], $this->brandBuckets($result));
    }

    // ---------------------------------------------------------------- AC-13

    public function testABadRangeOfEveryKindAddsNothingToAGoodOne(): void
    {
        $this->buildTheRangeFixture();
        $good = $this->strings('6', '8');
        $bound = static fn (?string $min, ?string $max): array => ['min' => $min, 'max' => $max];
        $mixed = $good + [
            'not-a-uuid' => $bound('1', '2'),
            Uuid::v7()->toRfc4122() => $bound('1', '2'),
            $this->brand->id()->toString() => $bound('1', '2'),
            $this->hasCase->id()->toString() => $bound('1', '2'),
            $this->serial->id()->toString() => $bound('1', '2'),
            $this->bassStrings->id()->toString() => $bound('1', '2'),
            $this->condition->id()->toString() => $bound(null, null),
        ];

        $alone = $this->browse($this->electric, $good, null, []);
        $withBad = $this->browse($this->electric, $mixed, null, [
            $this->strings->id()->toString() => ['6'], // f values on a number facet
        ]);

        self::assertSame(2, $alone->total());
        self::assertCount(1, $alone->query()->selections());
        self::assertSame($this->snapshot($alone), $this->snapshot($withBad));
    }

    public function testAnInvertedRangeIsSwappedAndAnUnparseableBoundIsDropped(): void
    {
        $this->buildTheRangeFixture();

        $swapped = $this->browse($this->electric, $this->strings('12', '6'));
        $straight = $this->browse($this->electric, $this->strings('6', '12'));
        $decimal = $this->browse($this->electric, $this->strings('6.5', null));
        $blank = $this->browse($this->electric, $this->strings('', ''));

        self::assertSame(3, $swapped->total());
        self::assertSame($this->snapshot($straight), $this->snapshot($swapped));
        self::assertSame([], $decimal->query()->selections());
        self::assertSame([], $blank->query()->selections());
        self::assertSame(4, $blank->total());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(SearchResult $result): array
    {
        return [
            'total' => $result->total(),
            'hits' => $this->hitIds($result),
            'facets' => array_map(
                static fn (Facet|RangeFacet $facet): array => $facet instanceof Facet
                    ? [$facet->attribute()->label(), array_map(static fn ($b): array => [$b->label(), $b->count(), $b->selected()], $facet->buckets())]
                    : [$facet->attribute()->label(), $facet->count(), $facet->observed()?->min(), $facet->observed()?->max(), $facet->selected()?->min(), $facet->selected()?->max()],
                $result->facets(),
            ),
            'selections' => \count($result->query()->selections()),
        ];
    }

    // ---------------------------------------------------------------- AC-14

    public function testAnArchivedNumberAttributeIsNoFacetItsRangeIsDroppedAndItsValuesStayUntouched(): void
    {
        $this->buildTheRangeFixture();
        $before = $this->rangeDb()->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE attribute_id = ? ORDER BY listing_id', [$this->strings->id()->toString()]);
        self::assertNotNull($this->rangeFacet($this->browse($this->electric), $this->strings), 'Control: a facet before the archive.');
        $archive = self::getContainer()->get(ArchiveAttributeHandler::class);
        self::assertInstanceOf(ArchiveAttributeHandler::class, $archive);

        $archive(new ArchiveAttribute($this->strings->id()->toString()));

        $after = $this->browse($this->electric);
        $filtered = $this->browse($this->electric, $this->strings('6', '6'));
        self::assertNull($this->rangeFacet($after, $this->strings));
        self::assertSame([], $filtered->query()->selections());
        self::assertSame($after->total(), $filtered->total());
        self::assertSame($before, $this->rangeDb()->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE attribute_id = ? ORDER BY listing_id', [$this->strings->id()->toString()]));
        self::assertCount(5, $before, 'Control: the values are still stored (N6a, N6b, N12, Dn, Wn).');
    }

    // ---------------------------------------------------------------- AC-15

    public function testNarrowingTheRulesAfterwardsNeverHidesAStoredValueOnARead(): void
    {
        $this->buildTheRangeFixture();
        $edit = self::getContainer()->get(EditAttributeHandler::class);
        self::assertInstanceOf(EditAttributeHandler::class, $edit);

        $edit(new EditAttribute($this->strings->id()->toString(), 'Strings', 'strings', false, 'pcs', 4, 10));

        self::assertSame(1, $this->totalAt($this->electric, '11', null), 'N12 holds 12, above the new maximum.');
        self::assertSame([6, 12], $this->observed($this->browse($this->electric), $this->strings));
        $below = $this->browse($this->electric, $this->strings('0', null));
        self::assertCount(1, $below->query()->selections(), 'A bound below the rules is not dropped.');
        self::assertSame(3, $below->total());
    }

    // ---------------------------------------------------------------- AC-16

    public function testPriceIsComparedWithinOneChosenCurrency(): void
    {
        $this->buildTheRangeFixture();
        $price = static fn (?string $currency, ?string $min = null, ?string $max = null): array => ['currency' => $currency, 'min' => $min, 'max' => $max];

        self::assertSame(3, $this->browse($this->electric, [], $price('EUR'))->total());
        $eurMax = $this->browse($this->electric, [], $price('EUR', null, '300'));
        self::assertSame(2, $eurMax->total());
        self::assertEqualsCanonicalizing([$this->listingN6a, $this->listingN0], $this->hitIds($eurMax));
        self::assertSame(1, $this->browse($this->electric, [], $price('EUR', '301'))->total());
        $usdMax = $this->browse($this->electric, [], $price('USD', null, '300'));
        self::assertSame([$this->listingN12], $this->hitIds($usdMax), 'The same 300 selects different listings in USD.');
        $noCurrency = $this->browse($this->electric, [], $price(null, '100'));
        self::assertNull($noCurrency->query()->price());
        self::assertSame(4, $noCurrency->total());
        $sek = $this->browse(null, [], $price('SEK'));
        self::assertSame([$this->listingBb], $this->hitIds($sek));
        self::assertSame(1, $sek->total());
    }

    // ---------------------------------------------------------------- AC-17

    public function testThePriceFilterAppliesToEveryCount(): void
    {
        $this->buildTheRangeFixture();

        $result = $this->browse($this->electric, [], ['currency' => 'USD', 'min' => null, 'max' => null]);

        self::assertSame(['Fender' => 1], $this->brandBuckets($result));
        $facet = $this->rangeFacet($result, $this->strings);
        self::assertNotNull($facet);
        self::assertSame(1, $facet->count());
        self::assertSame([12, 12], $this->observed($result, $this->strings));
    }

    // ---------------------------------------------------------------- AC-18

    public function testValuesAboveInt32AreComparedAndReportedWithoutCasts(): void
    {
        $this->buildTheRangeFixture();

        $result = $this->browse($this->bass, [$this->bassStrings->id()->toString() => ['min' => '4999999999', 'max' => null]]);

        self::assertSame(1, $result->total());
        self::assertSame([5000000000, 5000000000], $this->observed($result, $this->bassStrings));
    }
}
