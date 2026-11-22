<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheFacetFixture;
use App\Tests\Support\ReadsListingPages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * search-browse AC-19 … AC-24: `GET /browse` through the kernel, as a guest. The fixture is the lifted
 * one (`BuildsTheFacetFixture`): at Guitars, Brand is `Fender 3, Gibson 1`, Fretboard wood `Maple 1,
 * Rosewood 2`, Condition `Used 4`, Finish `Gloss 1`; four listings (P1, P1b, S, F).
 */
final class BrowsePageTest extends WebTestCase
{
    use BuildsTheFacetFixture;
    use ReadsListingPages;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = $this->facetDb();
        $this->buildTheFacetFixture();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $query
     */
    private function browseUrl(array $query = []): string
    {
        return '/browse'.([] === $query ? '' : '?'.http_build_query($query));
    }

    /**
     * @return list<string> every SQL statement the GET sent
     */
    private function recordedGet(string $uri): array
    {
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $uri);
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        return $sql;
    }

    /**
     * @param list<string> $sql
     *
     * @return list<string> the statements that read Search's tables (the adapter's own, not the outline's)
     */
    private function searchStatements(array $sql): array
    {
        return array_values(array_filter($sql, static fn (string $q): bool => 1 === preg_match('/\b(?:listing_listing|listing_attribute_value|catalog_attribute\w*)\b/', $q)));
    }

    /**
     * @param list<string> $sql
     */
    private function assertNoWrite(array $sql): void
    {
        foreach ($sql as $query) {
            self::assertSame(0, preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $query), 'A write statement: '.$query);
        }
    }

    private function brandId(): string
    {
        return $this->brand->id()->toString();
    }

    private function mainOf(Crawler $page): string
    {
        return $page->filter('main.search-browse')->outerHtml();
    }

    /**
     * @return array<int|string, mixed> the decoded query string of a link
     */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return $query;
    }

    // ---------------------------------------------------------------- AC-19

    public function testAGuestSeesTheBreadcrumbChildLinksFacetFormTotalAndHits(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->guitars->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Guitars', trim($page->filter('main.search-browse h1')->text()));
        $crumbs = $page->filter('nav.search-breadcrumb a')->each(static fn (Crawler $a): array => [trim($a->text()), (string) $a->attr('href')]);
        self::assertSame(
            [['Instruments', $this->browseUrl(['category' => $this->instruments->id()->toString()])], ['Guitars', $this->browseUrl(['category' => $this->guitars->id()->toString()])]],
            \array_slice($crumbs, -2),
        );
        self::assertSame(
            [['Electric', $this->browseUrl(['category' => $this->electric->id()->toString()])]],
            $page->filter('ul.search-categories a')->each(static fn (Crawler $a): array => [trim($a->text()), (string) $a->attr('href')]),
        );
        self::assertSame('4 listings', trim($page->filter('section.search-results > p')->first()->text()));
        self::assertCount(4, $page->filter('ol li.search-hit'));
        self::assertSame('noindex', $page->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $page->filter('main.search-browse script'), 'Facets work by submitting the form: no script is needed.');
    }

    public function testTheFacetFormIsOneGetFormWithAFieldsetPerFacetAndACheckboxPerBucket(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->guitars->id()->toString()]));

        $forms = $page->filter('form[method="get"][action="/browse"]');
        self::assertCount(1, $forms);
        self::assertSame($this->guitars->id()->toString(), $forms->filter('input[type="hidden"][name="category"]')->attr('value'));
        self::assertCount(1, $forms->filter('button[type="submit"]'));
        self::assertSame($this->browseUrl(['category' => $this->guitars->id()->toString()]), $forms->filter('a')->last()->attr('href'));
        self::assertSame('Clear filters', trim($forms->filter('a')->last()->text()));

        $fieldsets = $forms->filter('fieldset.search-facet');
        self::assertSame(['Condition', 'Finish', 'Fretboard wood', 'Brand'], $fieldsets->each(static fn (Crawler $f): string => trim($f->filter('legend')->text())));
        $brand = $fieldsets->eq(3);
        self::assertSame(['Fender (3)', 'Gibson (1)'], $brand->filter('label')->each(static fn (Crawler $l): string => preg_replace('/\s+/', ' ', trim($l->text())) ?? ''));
        self::assertSame(
            [[\sprintf('f[%s][]', $this->brandId()), $this->optionIdOf($this->brand, 'Fender')], [\sprintf('f[%s][]', $this->brandId()), $this->optionIdOf($this->brand, 'Gibson')]],
            $brand->filter('input[type="checkbox"]')->each(static fn (Crawler $i): array => [(string) $i->attr('name'), (string) $i->attr('value')]),
        );
        self::assertCount(0, $brand->filter('input[checked]'), 'Nothing selected, nothing checked.');
    }

    public function testASelectedBucketIsCheckedAndTheTotalAndHitsNarrow(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->guitars->id()->toString(), 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Gibson')]]]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [$this->optionIdOf($this->brand, 'Gibson')],
            $page->filter('input[type="checkbox"][checked]')->each(static fn (Crawler $i): string => (string) $i->attr('value')),
        );
        self::assertSame('1 listing', trim($page->filter('section.search-results > p')->first()->text()));
        self::assertSame($this->listingP1b, basename((string) $page->filter('li.search-hit a')->attr('href')));
    }

    public function testEachHitLinksTheListingWithTitlePriceAndDate(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->guitars->id()->toString()]));

        $hit = $page->filter('li.search-hit')->reduce(fn (Crawler $li): bool => str_ends_with((string) $li->filter('a')->attr('href'), $this->listingF))->first();
        self::assertSame('/listings/'.$this->listingF, $hit->filter('a')->attr('href'));
        self::assertSame('Fender Precision V', trim($hit->filter('a')->text()));
        self::assertSame('1234.50 EUR', trim($hit->filter('span')->text()));
        self::assertSame('20 Nov 2026', trim($hit->filter('time')->text()));
        self::assertNotSame('', (string) $hit->filter('time')->attr('datetime'));
    }

    public function testACategoryWithNoPublishedListingsSaysSoShowsNoFacetsAndStillLinksItsChildren(): void
    {
        $parent = CategoryFactory::createOne(['name' => CategoryName::fromString('Empty parent'), 'slug' => CategorySlug::fromString('empty-parent')]);
        CategoryFactory::new(['name' => CategoryName::fromString('Empty child'), 'slug' => CategorySlug::fromString('empty-child')])->childOf($parent)->create();

        $page = $this->client->request('GET', $this->browseUrl(['category' => $parent->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('No listings found.', $page->filter('section.search-results')->text());
        self::assertCount(0, $page->filter('form.search-facets'));
        self::assertSame(['Empty child'], $page->filter('ul.search-categories a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    public function testWithNoCategoryThePageListsEveryPublishedListingTheRootsAndNoFacetForm(): void
    {
        $page = $this->client->request('GET', '/browse');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('All categories', trim($page->filter('main.search-browse h1')->text()));
        self::assertCount(5, $page->filter('li.search-hit'));
        self::assertCount(0, $page->filter('form.search-facets'));
        self::assertSame(['Instruments'], $page->filter('ul.search-categories a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    /**
     * 21 published listings in one category, each holding Finish = Gloss: two pages under one selection.
     *
     * @return array{string, string, string} category id, Finish attribute id, Gloss option id
     */
    private function twentyOneGlossy(): array
    {
        $category = CategoryFactory::createOne(['name' => CategoryName::fromString('Pedals'), 'slug' => CategorySlug::fromString('pedals')]);
        $finish = AttributeFactory::new()->inCategory($category)->coded('pedal-finish')->labelled('Pedal finish')->choice(['Gloss'])->create();
        $gloss = $this->optionIdOf($finish, 'Gloss');
        for ($i = 0; $i < 21; ++$i) {
            ListingFactory::new()->inCategory($category)->withValues([$finish->id()->toString() => $gloss])->published()->create();
        }

        return [$category->id()->toString(), $finish->id()->toString(), $gloss];
    }

    public function testPreviousAndNextKeepTheCategoryAndTheSelection(): void
    {
        [$category, $finish, $gloss] = $this->twentyOneGlossy();
        $selection = ['category' => $category, 'f' => [$finish => [$gloss]]];

        $first = $this->client->request('GET', $this->browseUrl($selection));
        self::assertCount(20, $first->filter('li.search-hit'));
        self::assertCount(0, $first->filter('nav.search-pager a[rel="prev"]'));
        self::assertSame($selection + ['page' => '2'], $this->queryOf((string) $first->filter('nav.search-pager a[rel="next"]')->attr('href')));

        $second = $this->client->request('GET', $this->browseUrl($selection + ['page' => 2]));
        self::assertCount(1, $second->filter('li.search-hit'));
        self::assertCount(0, $second->filter('nav.search-pager a[rel="next"]'));
        self::assertSame($selection + ['page' => '1'], $this->queryOf((string) $second->filter('nav.search-pager a[rel="prev"]')->attr('href')));
    }

    public function testAPagePastTheEndIsAnEmptyPageWithTheTotalAndALinkToTheFirstPage(): void
    {
        [$category, $finish, $gloss] = $this->twentyOneGlossy();
        $selection = ['category' => $category, 'f' => [$finish => [$gloss]]];

        $page = $this->client->request('GET', $this->browseUrl($selection + ['page' => 3]));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('li.search-hit'));
        self::assertStringContainsString('No listings found.', $page->filter('section.search-results')->text());
        self::assertSame('21 listings', trim($page->filter('section.search-results > p')->first()->text()));
        $first = $page->filter('nav.search-pager a')->reduce(static fn (Crawler $a): bool => 'First page' === trim($a->text()));
        self::assertCount(1, $first);
        self::assertSame($selection, $this->queryOf((string) $first->attr('href')));
        self::assertCount(0, $page->filter('nav.search-pager a[rel="next"]'));
    }

    // ---------------------------------------------------------------- AC-20

    public function testNoStatementTheGetSendsNamesSellerId(): void
    {
        $sql = $this->recordedGet($this->browseUrl(['category' => $this->guitars->id()->toString(), 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Gibson')]]]));

        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $this->searchStatements($sql), 'The recorder saw no Search statement, so "never seller_id" would pass vacuously.');
        foreach ($sql as $query) {
            self::assertStringNotContainsString('seller_id', $query);
        }
    }

    public function testThePageContainsNothingFromTheSellersAccount(): void
    {
        $account = $this->db->fetchAssociative('SELECT * FROM identity_user LIMIT 1');
        self::assertIsArray($account);
        $secrets = [];
        foreach ($account as $value) {
            if (\is_string($value) && \strlen($value) >= 4) {
                $secrets[] = $value;
            }
        }
        self::assertGreaterThan(2, \count($secrets), 'The account row gave nothing to look for: the scan below would prove nothing.');
        self::assertSame(1, $this->db->fetchOne('SELECT count(DISTINCT seller_id) FROM listing_listing'), 'Control: every listing belongs to this one seller.');

        $this->client->request('GET', $this->browseUrl(['category' => $this->guitars->id()->toString()]));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Fender Precision V', $html, 'Control: the hits are on the page.');
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    // ---------------------------------------------------------------- AC-21

    /**
     * @return array<string, array{string}>
     */
    public static function badCategories(): array
    {
        return [
            'malformed string' => ['category=not-a-uuid'],
            'uuid with a trailing newline' => ['category=018f5b2a-0000-7000-8000-000000000000%0A'],
            'an array' => ['category[]=x'],
            'an array of arrays' => ['category[a][b]=x'],
        ];
    }

    public function testAnUnknownMalformedNewlineAndArrayCategoryAreOneIdentical404(): void
    {
        $unknown = Uuid::v7()->toRfc4122();
        $queries = ['category='.$unknown, ...array_column(self::badCategories(), 0)];
        $ids = [$unknown, 'not-a-uuid', '018f5b2a-0000-7000-8000-000000000000'];

        $bodies = [];
        foreach ($queries as $query) {
            $this->client->request('GET', '/browse?'.$query);
            self::assertResponseStatusCodeSame(404, $query);
            // The test-env debug 404 also embeds the request URL the test itself sent (BrowserKit's call trace).
            $body = preg_replace("#'http://localhost/browse[^']*'#", "'URL'", (string) $this->client->getResponse()->getContent()) ?? '';
            $bodies[] = $this->normalised($body, $ids);
        }

        self::assertCount(1, array_unique($bodies), 'The 404 bodies differ, so one of them says whether the category exists.');
    }

    #[DataProvider('badCategories')]
    public function testAMalformedCategoryIssuesNoSearchStatement(string $query): void
    {
        $sql = $this->recordedGet('/browse?'.$query);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->searchStatements($sql), "Statements:\n".implode("\n", $sql));
        self::assertSame([], array_values(array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_') || str_contains($q, 'listing_'))));
    }

    public function testAnEmptyCategoryParameterIsNotAnErrorItBrowsesEverything(): void
    {
        $this->client->request('GET', '/browse?category=');

        self::assertResponseStatusCodeSame(200);
    }

    // ---------------------------------------------------------------- AC-22

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileQueries(): iterable
    {
        $uuid = '018f5b2a-0000-7000-8000-0000000000aa';
        $thirty = implode('&', array_map(static fn (int $i): string => \sprintf('f[018f5b2a-0000-7000-8000-%012d][]=yes', $i), range(1, 30)));

        yield 'f is a string' => ['f=x'];
        yield 'f is a list' => ['f[]=x'];
        yield 'f nested three deep' => ['f[a][b][c]=1'];
        yield 'an attribute holding a string' => ['f['.$uuid.']=not-an-array'];
        yield 'page is an array' => ['page[]=1'];
        yield 'page is not a number' => ['page=abc'];
        yield 'page is negative' => ['page=-1'];
        yield 'page overflows an integer' => ['page=99999999999999999999'];
        yield 'thirty attributes' => [$thirty];
        yield 'NUL in a key and a value' => ['f[%00][]=%00'];
        yield 'NUL in a value of a well-formed attribute' => ['f['.$uuid.'][]=%00'];
        yield 'invalid UTF-8 in a key and a value' => ['f[%FF%FE][]=%FF%FE'];
        yield 'invalid UTF-8 in a value of a well-formed attribute' => ['f['.$uuid.'][]=%FF%FE'];
    }

    #[DataProvider('hostileQueries')]
    public function testAHostileParameterIsA200AndTheDroppedPartsAreIgnored(string $hostile): void
    {
        $category = $this->guitars->id()->toString();
        $baseline = $this->mainOf($this->client->request('GET', $this->browseUrl(['category' => $category])));
        self::assertResponseStatusCodeSame(200);

        $page = $this->client->request('GET', '/browse?category='.$category.'&'.$hostile);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($baseline, $this->mainOf($page), 'Everything dropped, so the page is the unfiltered page.');
    }

    public function testAHostileFilterBesideAGoodOneKeepsOnlyTheGoodOne(): void
    {
        $category = $this->guitars->id()->toString();
        $good = $this->mainOf($this->client->request('GET', $this->browseUrl(['category' => $category, 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Gibson')]]])));

        $page = $this->client->request('GET', '/browse?'.http_build_query(['category' => $category, 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Gibson')]]]).'&f[%FF][]=x&page[]=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($good, $this->mainOf($page));
    }

    // ---------------------------------------------------------------- AC-23

    /**
     * @return array<string, mixed> a query selecting Brand = Fender (k = 1)
     */
    private function oneSelection(): array
    {
        return ['category' => $this->guitars->id()->toString(), 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Fender')]]];
    }

    public function testTheGetSendsAtMostFivePlusOneStatementPerSelectedFacetAndNoneWrites(): void
    {
        $category = $this->guitars->id()->toString();
        $two = ['category' => $category, 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Fender')], $this->fretboardWood => [$this->wood('Rosewood')]]];

        foreach ([[0, ['category' => $category]], [1, $this->oneSelection()], [2, $two]] as [$k, $query]) {
            $sql = $this->recordedGet($this->browseUrl($query));

            self::assertResponseStatusCodeSame(200);
            self::assertNotSame([], $this->searchStatements($sql), 'The recorder saw no Search statement, so the bound proves nothing.');
            self::assertLessThanOrEqual(5 + $k, \count($sql), "k = $k, statements:\n".implode("\n", $sql));
            $this->assertNoWrite($sql);
        }
    }

    public function testTheStatementCountIsTheSameForABrandOfTwoOptionsAndOfFifty(): void
    {
        $counts = [];
        foreach ([2, 50] as $options) {
            $category = CategoryFactory::createOne(['name' => CategoryName::fromString('Cat '.$options), 'slug' => CategorySlug::fromString('cat-'.$options)]);
            $labels = array_map(static fn (int $i): string => 'Brand '.$i, range(1, $options));
            $wide = AttributeFactory::new()->inCategory($category)->coded('wide-'.$options)->labelled('Wide '.$options)->choice($labels)->create();
            $pick = $this->optionIdOf($wide, 'Brand '.$options);
            foreach ([$pick, $this->optionIdOf($wide, 'Brand 1'), $pick] as $value) {
                ListingFactory::new()->inCategory($category)->withValues([$wide->id()->toString() => $value])->published()->create();
            }
            self::assertSame($options, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$wide->id()->toString()]));

            $sql = $this->recordedGet($this->browseUrl(['category' => $category->id()->toString(), 'f' => [$wide->id()->toString() => [$pick]]]));

            self::assertResponseStatusCodeSame(200);
            self::assertCount(2, $this->client->getCrawler()->filter('li.search-hit'), 'Control: the selection narrows three listings to two.');
            self::assertSame([], array_values(array_filter($sql, static fn (string $q): bool => 1 === preg_match('/FROM catalog_attribute_option\s+WHERE attribute_id/i', $q))), 'Something loaded an attribute\'s whole option list.');
            $counts[$options] = $sql;
        }

        self::assertSame(\count($counts[2]), \count($counts[50]), "Two options:\n".implode("\n", $counts[2])."\nFifty:\n".implode("\n", $counts[50]));
    }

    public function testBrowsingWithNoCategorySendsAtMostThreeStatements(): void
    {
        $sql = $this->recordedGet('/browse');

        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $this->searchStatements($sql), 'The recorder saw no Search statement, so the bound proves nothing.');
        self::assertLessThanOrEqual(3, \count($sql), "Statements:\n".implode("\n", $sql));
        $this->assertNoWrite($sql);
    }

    // ---------------------------------------------------------------- AC-24

    public function testASelectionTravelsToAChildCategoryWhereItsFacetIsInheritedAndIsDroppedAtAnAncestorWhereItIsNot(): void
    {
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $atGuitars = $this->client->request('GET', $this->browseUrl($this->oneSelection()));
        self::assertSame(3, $atGuitars->filter('li.search-hit')->count());

        // Guitars -> Electric: the link carries f, and Brand (defined on Guitars) is in Electric's lineage.
        $toElectric = $atGuitars->filter('ul.search-categories a')->first();
        self::assertSame(['category' => $this->electric->id()->toString(), 'f' => [$this->brandId() => [$fender]]], $this->queryOf((string) $toElectric->attr('href')));
        $atElectric = $this->client->click($toElectric->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$fender], $atElectric->filter('input[type="checkbox"][checked]')->each(static fn (Crawler $i): string => (string) $i->attr('value')));
        self::assertSame(3, $atElectric->filter('li.search-hit')->count());

        // Electric -> Instruments: the breadcrumb carries f too, but Brand is not in Instruments' lineage.
        $toInstruments = $atElectric->filter('nav.search-breadcrumb a')->reduce(static fn (Crawler $a): bool => 'Instruments' === trim($a->text()));
        self::assertSame(['category' => $this->instruments->id()->toString(), 'f' => [$this->brandId() => [$fender]]], $this->queryOf((string) $toInstruments->attr('href')));
        $atInstruments = $this->client->click($toInstruments->link());
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $atInstruments->filter('input[type="checkbox"][checked]'));
        self::assertCount(0, $atInstruments->filter(\sprintf('input[name="f[%s][]"]', $this->brandId())));
        self::assertSame(5, $atInstruments->filter('li.search-hit')->count(), 'The dropped selection no longer narrows.');
        foreach ($atInstruments->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
            self::assertArrayNotHasKey('f', $this->queryOf($href), 'A link on the page still carries the dropped selection: '.$href);
        }
    }
}
