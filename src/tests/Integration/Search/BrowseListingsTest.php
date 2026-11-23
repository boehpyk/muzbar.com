<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Application\Search\Handler\BrowseListingsHandler;
use App\Application\Search\Query\BrowseListings;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Search\Exception\UnknownCategory;
use App\Domain\Search\ValueObject\Facet;
use App\Domain\Search\ValueObject\RangeFacet;
use App\Domain\Search\ValueObject\SearchResult;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheFacetFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * search-browse AC-6 … AC-17, through `BrowseListingsHandler` (never the adapter's methods one by one:
 * S-3's clauses live in `facetSchemaFor()`, S-1/S-2/S-4's in the counts). Real database, DAMA.
 *
 * AC-6 … AC-14 run over the lifted fixture (`BuildsTheFacetFixture`, phase1-gate's section B). Each
 * odd row of it exists for one clause only: Dr and W for `status` (AC-6), P2 for the subtree (AC-7),
 * F for the archived attribute (AC-8), S for the archived option (AC-10). AC-15 … AC-17 build their own.
 *
 * "Total" in a comment is `SearchResult::total()` — listings matching every selection; a bucket's
 * number is its count (disjunctive, S-7).
 */
final class BrowseListingsTest extends KernelTestCase
{
    use BuildsTheFacetFixture;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = $this->facetDb();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, list<string>> $filters
     */
    private function browse(?Category $category, array $filters = [], int $page = 1): SearchResult
    {
        return $this->browseId($category?->id()->toString(), $filters, $page);
    }

    /**
     * @param array<string, list<string>> $filters
     */
    private function browseId(?string $category, array $filters = [], int $page = 1): SearchResult
    {
        $handler = self::getContainer()->get(BrowseListingsHandler::class);
        self::assertInstanceOf(BrowseListingsHandler::class, $handler);

        return $handler(new BrowseListings($category, $filters, [], ['currency' => null, 'min' => null, 'max' => null], null, $page));
    }

    /**
     * @return array<string, int> bucket label => count of the facet called `$label`; empty when it is not shown
     */
    private function buckets(SearchResult $result, string $label): array
    {
        $counts = [];
        foreach ($result->facets() as $facet) {
            if ($facet instanceof Facet && $label === $facet->attribute()->label()) {
                foreach ($facet->buckets() as $bucket) {
                    $counts[$bucket->label()] = $bucket->count();
                }
            }
        }

        return $counts;
    }

    /**
     * @return list<string> the labels of every facet shown
     */
    private function facetLabels(SearchResult $result): array
    {
        return array_map(static fn ($facet): string => $facet->attribute()->label(), $result->facets());
    }

    /**
     * @return list<string> the hit ids, in page order
     */
    private function hitIds(SearchResult $result): array
    {
        return array_map(static fn ($hit): string => $hit->id(), $result->hits());
    }

    /**
     * Everything a page shows, comparable: total, hit ids, every facet's buckets, selections kept.
     *
     * @return array<string, mixed>
     */
    private function snapshot(SearchResult $result): array
    {
        return [
            'total' => $result->total(),
            'hits' => $this->hitIds($result),
            'facets' => array_map(
                static fn (Facet|RangeFacet $facet): array => $facet instanceof Facet
                    ? [$facet->attribute()->label(), array_map(static fn ($b): array => [$b->value(), $b->label(), $b->count(), $b->selected()], $facet->buckets())]
                    : [$facet->attribute()->label(), $facet->count(), $facet->observed()?->min(), $facet->observed()?->max(), $facet->selected()?->min(), $facet->selected()?->max()],
                $result->facets(),
            ),
            'selections' => \count($result->query()->selections()),
        ];
    }

    /**
     * @param list<string> $params
     */
    private function id(string $sql, array $params = []): string
    {
        $found = $this->db->fetchOne($sql, $params);
        self::assertIsString($found, 'The fixture lookup found no row.');

        return $found;
    }

    private function brandOption(string $label): string
    {
        return $this->optionIdOf($this->brand, $label);
    }

    // ---------------------------------------------------------------- AC-6 (S-1)

    public function testOnlyPublishedListingsAreFoundOrCounted(): void
    {
        $this->buildTheFacetFixture();
        $guitars = $this->browse($this->guitars);

        self::assertSame(['Maple' => 1, 'Rosewood' => 2], $this->buckets($guitars, 'Fretboard wood'));
        // Control: four Rosewood values sit below Guitars (P1, P1b, Dr, W), and all three statuses are stored.
        self::assertSame(4, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE v.attribute_id = ? AND v.option_id = ? AND l.category_id = ?', [$this->fretboardWood, $this->wood('Rosewood'), $this->electric->id()->toString()]));
        self::assertSame(['draft', 'published', 'withdrawn'], $this->db->fetchFirstColumn('SELECT DISTINCT status FROM listing_listing ORDER BY status'));
        // The total counts P1, P1b, S, F only — not Dr, not W.
        self::assertSame(4, $guitars->total());
        self::assertEqualsCanonicalizing([$this->listingP1, $this->listingP1b, $this->listingS, $this->listingF], $this->hitIds($guitars));
    }

    // ---------------------------------------------------------------- AC-7 (S-2)

    public function testOnlyListingsInTheBrowsedSubtreeAreFoundOrCounted(): void
    {
        $this->buildTheFacetFixture();

        $instruments = $this->browse($this->instruments);
        $guitars = $this->browse($this->guitars);

        self::assertSame(['Maple' => 1, 'Rosewood' => 3], $this->buckets($instruments, 'Fretboard wood'));
        self::assertSame(['Maple' => 1, 'Rosewood' => 2], $this->buckets($guitars, 'Fretboard wood'));
        // P2 (Bass) is the only difference.
        self::assertSame(5, $instruments->total());
        self::assertSame(4, $guitars->total());
        self::assertContains($this->listingP2, $this->hitIds($instruments));
        self::assertNotContains($this->listingP2, $this->hitIds($guitars));
    }

    // ---------------------------------------------------------------- AC-8 (S-3, archived attribute)

    public function testAnArchivedAttributeIsNoFacetItsFilterIsDroppedAndItsStoredValueStaysUntouched(): void
    {
        $this->buildTheFacetFixture();
        $gloss = $this->optionIdOf($this->finishAttribute(), 'Gloss');
        $before = $this->valueRowsOf($this->listingF);
        $unfiltered = $this->browse($this->instruments);
        self::assertSame(['Gloss' => 1], $this->buckets($unfiltered, 'Finish'), 'Control: Finish is a facet before the archive.');

        $archive = self::getContainer()->get(ArchiveAttributeHandler::class);
        self::assertInstanceOf(ArchiveAttributeHandler::class, $archive);
        $archive(new ArchiveAttribute($this->finish));

        $after = $this->browse($this->instruments);
        self::assertNotContains('Finish', $this->facetLabels($after));
        $filtered = $this->browse($this->instruments, [$this->finish => [$gloss]]);
        self::assertSame([], $filtered->query()->selections());
        self::assertSame($after->total(), $filtered->total());
        self::assertSame($this->snapshot($after), $this->snapshot($filtered));
        self::assertSame($before, $this->valueRowsOf($this->listingF));
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->listingF, $this->finish]), 'Control: the dormant value is still stored.');
    }

    // ---------------------------------------------------------------- AC-9 (S-3, lineage)

    public function testAnAttributeIsAFacetOnlyWhereItIsInTheBrowsedCategorysLineage(): void
    {
        $this->buildTheFacetFixture();

        self::assertSame(['Fender' => 3, 'Gibson' => 1], $this->buckets($this->browse($this->guitars), 'Brand'));
        self::assertNotContains('Brand', $this->facetLabels($this->browse($this->instruments)));
        // Control: published listings below Instruments do hold Brand; it is just not in Instruments' schema.
        self::assertSame(4, $this->db->fetchOne("SELECT count(*) FROM listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE v.attribute_id = ? AND l.status = 'published'", [$this->brand->id()->toString()]));
    }

    public function testAnInheritedFacetShowsAtTheLeafBelowItsDefiningCategory(): void
    {
        $this->buildTheFacetFixture();

        // Condition and the Instruments' two come from the grandparent, Brand from Guitars. Strings (number) and
        // Serial (text) are no facets; Has case is one, but no fixture listing holds it, so it has no bucket to show.
        self::assertEqualsCanonicalizing(['Brand', 'Condition', 'Finish', 'Fretboard wood'], $this->facetLabels($this->browse($this->electric)));
    }

    // ---------------------------------------------------------------- AC-10 (S-4, archived option)

    public function testAnArchivedOptionGetsNoBucketIsNotSelectableAndItsStoredValueStaysUntouched(): void
    {
        $this->buildTheFacetFixture();
        $maple = $this->wood('Maple');
        $before = $this->valueRowsOf($this->listingS);
        $unfiltered = $this->browse($this->guitars);
        $archive = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $archive);

        $archive(new ArchiveAttributeOption($this->fretboardWood, $maple));

        $after = $this->browse($this->guitars);
        self::assertSame(['Rosewood' => 2], $this->buckets($after, 'Fretboard wood'));
        // Selecting it is dropped: the page is the unfiltered page, total unchanged.
        $selected = $this->browse($this->guitars, [$this->fretboardWood => [$maple]]);
        self::assertSame([], $selected->query()->selections());
        self::assertSame($unfiltered->total(), $selected->total());
        self::assertSame($this->snapshot($after), $this->snapshot($selected));
        self::assertSame($before, $this->valueRowsOf($this->listingS));
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->listingS, $this->fretboardWood]), 'Control: the stale value is still stored.');
    }

    // ---------------------------------------------------------------- AC-11 (dormant by move)

    /**
     * Holds by construction, not by a dormancy guard: the facets are the browsed category's
     * lineage, which is in every subtree listing's lineage, so a value whose attribute left the
     * lineage can never belong to a facet. P2 stops counting because it leaves Instruments' subtree
     * (S-2). Do not go looking for a fifth clause.
     */
    public function testAValueMadeDormantByAMoveCountsNowhere(): void
    {
        $this->buildTheFacetFixture();
        $other = CategoryFactory::createOne(['name' => CategoryName::fromString('Other'), 'slug' => CategorySlug::fromString('other')]);
        self::assertSame(3, $this->buckets($this->browse($this->instruments), 'Fretboard wood')['Rosewood'], 'Control: P2 counts at Instruments before the move.');
        $edit = self::getContainer()->get(EditCategoryHandler::class);
        self::assertInstanceOf(EditCategoryHandler::class, $edit);

        // P2's Fretboard wood value comes from Instruments; Bass leaves its lineage.
        $edit(new EditCategory($this->bass->id()->toString(), 'Bass', 'bass', $other->id()->toString()));

        $atOther = $this->browse($other);
        self::assertNotContains('Fretboard wood', $this->facetLabels($atOther));
        self::assertSame(1, $atOther->total(), 'P2 is still browsable under its new parent.');
        self::assertSame(['Maple' => 1, 'Rosewood' => 2], $this->buckets($this->browse($this->instruments), 'Fretboard wood'));
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->listingP2, $this->fretboardWood]), 'Control: the dormant value is still stored.');
    }

    // ---------------------------------------------------------------- AC-12 (S-5 end to end)

    public function testABadSelectionOfEveryKindAddsNothingToAGoodOne(): void
    {
        $this->buildTheFacetFixture();
        $define = self::getContainer()->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $define);
        // A real facet, but of Bass — a sibling branch of Guitars.
        $pickups = $define(new DefineAttribute($this->bass->id()->toString(), 'choice', 'Bass pickups', 'bass-pickups', false, optionLabels: ['Split']))->toString();
        $split = $this->id('SELECT id FROM catalog_attribute_option WHERE attribute_id = ?', [$pickups]);
        $archive = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $archive);
        $maple = $this->wood('Maple');
        $archive(new ArchiveAttributeOption($this->fretboardWood, $maple));
        $brand = $this->brand->id()->toString();
        $fender = $this->brandOption('Fender');

        $good = $this->browse($this->guitars, [$brand => [$fender]]);
        $mixed = $this->browse($this->guitars, [
            $brand => [$fender, 'yes', 'not-a-uuid', Uuid::v7()->toRfc4122()],
            'not-a-uuid' => [$fender],
            Uuid::v7()->toRfc4122() => [$fender],
            $this->fretboardWood => [$this->brandOption('Gibson'), $maple],
            $this->condition->id()->toString() => ['yes'],
            $pickups => [$split],
        ]);

        self::assertSame(3, $good->total(), 'Control: the good selection alone narrows to the three Fender listings.');
        self::assertSame(1, \count($good->query()->selections()));
        self::assertSame($this->snapshot($good), $this->snapshot($mixed));
    }

    // ---------------------------------------------------------------- AC-13 (S-6)

    public function testSeveralBucketsOfOneFacetAreOrAndDifferentFacetsAreAnd(): void
    {
        $this->buildTheFacetFixture();
        $brand = $this->brand->id()->toString();
        $bothBrands = [$brand => [$this->brandOption('Fender'), $this->brandOption('Gibson')]];

        $either = $this->browse($this->guitars, $bothBrands);
        $and = $this->browse($this->guitars, $bothBrands + [$this->fretboardWood => [$this->wood('Rosewood')]]);

        self::assertSame(4, $either->total());
        self::assertSame(2, $and->total());
        self::assertEqualsCanonicalizing([$this->listingP1, $this->listingP1b], $this->hitIds($and));
    }

    // ---------------------------------------------------------------- AC-14 (S-7, disjunctive)

    public function testACountIgnoresItsOwnFacetsSelectionAndAppliesEveryOther(): void
    {
        $this->buildTheFacetFixture();

        $result = $this->browse($this->guitars, [$this->brand->id()->toString() => [$this->brandOption('Gibson')]]);

        self::assertSame(['Fender' => 3, 'Gibson' => 1], $this->buckets($result, 'Brand'), 'Brand ignores its own selection.');
        self::assertSame(['Rosewood' => 1], $this->buckets($result, 'Fretboard wood'), 'Fretboard wood applies the Gibson selection.');
        self::assertSame(1, $result->total());
        self::assertSame(['Gibson'], $this->selectedLabels($result));
    }

    public function testASelectedBucketThatMatchesNothingElseStaysVisibleAtCountZero(): void
    {
        $this->buildTheFacetFixture();

        // Gibson is only P1b, which holds Rosewood: Gibson AND Maple matches nothing.
        $result = $this->browse($this->guitars, [
            $this->brand->id()->toString() => [$this->brandOption('Gibson')],
            $this->fretboardWood => [$this->wood('Maple')],
        ]);

        self::assertSame(0, $result->total());
        self::assertSame(['Maple' => 0, 'Rosewood' => 1], $this->buckets($result, 'Fretboard wood'));
        self::assertSame(['Fender' => 1, 'Gibson' => 0], $this->buckets($result, 'Brand'));
        self::assertSame(['Maple', 'Gibson'], $this->selectedLabels($result), 'Both stay, ticked, so they can be unchecked.');
    }

    /**
     * @return list<string> the label of every ticked bucket, in facet order
     */
    private function selectedLabels(SearchResult $result): array
    {
        $labels = [];
        foreach ($result->facets() as $facet) {
            foreach ($facet instanceof Facet ? $facet->buckets() : [] as $bucket) {
                if ($bucket->selected()) {
                    $labels[] = $bucket->label();
                }
            }
        }

        return $labels;
    }

    // ---------------------------------------------------------------- AC-15 (S-8)

    private function gear(): Category
    {
        return CategoryFactory::createOne(['name' => CategoryName::fromString('Gear'), 'slug' => CategorySlug::fromString('gear')]);
    }

    public function testAListingDraftedFirstButPublishedLastIsFirst(): void
    {
        $gear = $this->gear();
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20 '.$time, new \DateTimeZone('UTC'));
        // Created (so id-minted) first, published last: by id descending it would be last.
        $first = ListingFactory::new()->inCategory($gear)->titled('Drafted first')->published($at('11:00:00'))->create()->id()->toString();
        $second = ListingFactory::new()->inCategory($gear)->titled('Drafted second')->published($at('10:00:00'))->create()->id()->toString();
        $third = ListingFactory::new()->inCategory($gear)->titled('Drafted third')->published($at('10:30:00'))->create()->id()->toString();
        self::assertSame([$third, $second, $first], $this->db->fetchFirstColumn('SELECT id FROM listing_listing ORDER BY id DESC'), 'Control: id order is the reverse of the expected order.');

        self::assertSame([$first, $third, $second], $this->hitIds($this->browse($gear)));
    }

    public function testListingsPublishedInTheSameSecondAreOrderedByIdDescending(): void
    {
        $gear = $this->gear();
        $ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $ids[] = ListingFactory::new()->inCategory($gear)->published()->create()->id()->toString();
        }
        // A test-only write: whatever the factory's clock did, all four share one instant.
        $this->db->executeStatement("UPDATE listing_listing SET published_at = '2026-11-20 10:00:00+00' WHERE category_id = ?", [$gear->id()->toString()]);
        self::assertSame(1, $this->db->fetchOne('SELECT count(DISTINCT published_at) FROM listing_listing'), 'Control: a genuine tie.');
        rsort($ids);

        self::assertSame($ids, $this->hitIds($this->browse($gear)));
    }

    public function testTwentyOnePublishedListingsPageTwentyThenOneThenNone(): void
    {
        $gear = $this->gear();
        for ($i = 0; $i < 21; ++$i) {
            ListingFactory::new()->inCategory($gear)->published()->create();
        }
        $all = $this->db->fetchFirstColumn("SELECT id FROM listing_listing WHERE status = 'published' ORDER BY id DESC");

        $one = $this->browse($gear, [], 1);
        $two = $this->browse($gear, [], 2);
        $three = $this->browse($gear, [], 3);

        self::assertCount(20, $one->hits());
        self::assertCount(1, $two->hits());
        self::assertSame([], $three->hits());
        foreach ([$one, $two, $three] as $page) {
            self::assertSame(21, $page->total());
        }
        self::assertSame($all, [...$this->hitIds($one), ...$this->hitIds($two)], 'Pages tile the ordered listings with no gap and no overlap.');
    }

    public function testAHitCarriesTitlePriceCurrencyAndPublicationInstant(): void
    {
        $gear = $this->gear();
        $listing = ListingFactory::new()->inCategory($gear)->titled('Fender Precision V')->published()->create();

        $hit = $this->browse($gear)->hits()[0];

        self::assertSame($listing->id()->toString(), $hit->id());
        self::assertSame('Fender Precision V', $hit->title());
        self::assertSame('1234.50', $hit->price());
        self::assertSame('EUR', $hit->currency());
        self::assertSame(
            new \DateTimeImmutable(ListingFactory::PUBLISHED_AT.' UTC')->getTimestamp(),
            new \DateTimeImmutable($hit->publishedAt())->getTimestamp(),
        );
    }

    // ---------------------------------------------------------------- AC-16 (boolean facet)

    public function testABooleanFacetShowsYesAndNoAndFiltersOnEither(): void
    {
        $this->buildTheValuesTree();
        $used = $this->optionIdOf($this->condition, 'Used');
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $base = [$this->condition->id()->toString() => $used, $this->brand->id()->toString() => $fender];
        $hasCase = $this->hasCase->id()->toString();
        foreach ([['yes'], ['yes'], ['no'], []] as $value) {
            ListingFactory::new()->inCategory($this->electric)->withValues([] === $value ? $base : $base + [$hasCase => $value[0]])->published()->create();
        }

        $all = $this->browse($this->electric);
        $no = $this->browse($this->electric, [$hasCase => ['no']]);
        $either = $this->browse($this->electric, [$hasCase => ['yes', 'no']]);

        self::assertSame(['Yes' => 2, 'No' => 1], $this->buckets($all, 'Has case'), 'false is counted too, in Yes-then-No order.');
        self::assertSame(4, $all->total());
        self::assertSame(1, $no->total());
        self::assertSame(3, $either->total(), 'Any value, not the listing with none.');
    }

    // ---------------------------------------------------------------- AC-17 (no category)

    public function testWithNoCategoryEveryPublishedListingIsListedNewestFirstWithNoFacetsAndAnyFilterDropped(): void
    {
        $a = $this->gear();
        $b = CategoryFactory::createOne(['name' => CategoryName::fromString('Amps'), 'slug' => CategorySlug::fromString('amps')]);
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20 '.$time, new \DateTimeZone('UTC'));
        $oldest = ListingFactory::new()->inCategory($a)->published($at('09:00:00'))->create()->id()->toString();
        $newest = ListingFactory::new()->inCategory($b)->published($at('11:00:00'))->create()->id()->toString();
        $middle = ListingFactory::new()->inCategory($a)->published($at('10:00:00'))->create()->id()->toString();
        ListingFactory::new()->inCategory($a)->create(); // a draft
        ListingFactory::new()->inCategory($b)->withdrawn($at('12:00:00'))->create();
        AttributeFactory::new()->inCategory($a)->coded('finish')->labelled('Finish')->choice(['Gloss'])->create();
        $finish = $this->id("SELECT id FROM catalog_attribute WHERE code = 'finish'");
        $gloss = $this->id('SELECT id FROM catalog_attribute_option WHERE attribute_id = ?', [$finish]);

        $result = $this->browseId(null, [$finish => [$gloss], 'x' => ['yes']]);

        self::assertSame([$newest, $middle, $oldest], $this->hitIds($result));
        self::assertSame(3, $result->total());
        self::assertSame([], $result->facets());
        self::assertSame([], $result->query()->selections());
        self::assertNull($result->query()->category());
    }

    // ---------------------------------------------------------------- the failure contract (404 signal)

    public function testAnUnknownCategoryAndAMalformedOneAreBothUnknownCategory(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid', Uuid::v7()->toRfc4122()."\n", ''] as $category) {
            try {
                $this->browseId($category);
                self::fail(\sprintf('"%s" browsed a result.', addcslashes($category, "\n")));
            } catch (UnknownCategory) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
