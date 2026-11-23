<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Tests\Factory\ListingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheRangeFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * search-range-facets AC-20 … AC-24: `GET /browse` through the kernel, as a guest, over
 * `BuildsTheRangeFixture`. At Electric: Strings holds 3 listings (6, 6, 12), 4 are published.
 * Sits beside `BrowsePageTest`, whose fixture and AC numbers belong to `search-browse`.
 */
final class BrowseRangePageTest extends WebTestCase
{
    use BuildsTheRangeFixture;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = $this->rangeDb();
        $this->buildTheRangeFixture();
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
     * @return array<int|string, mixed> the decoded query string of a link
     */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return $query;
    }

    private function mainOf(Crawler $page): string
    {
        return $page->filter('main.search-browse')->outerHtml();
    }

    private function stringsId(): string
    {
        return $this->strings->id()->toString();
    }

    private function total(Crawler $page): string
    {
        return trim($page->filter('section.search-results > p')->first()->text());
    }

    // ---------------------------------------------------------------- AC-20

    public function testTheElectricFormHasARangeFieldsetWithUnitCountAndObservedRange(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->electric->id()->toString()]));

        self::assertResponseStatusCodeSame(200);
        $fieldset = $page->filter('form.search-facets fieldset.search-facet--range');
        self::assertCount(1, $fieldset, 'Strings is the only number facet at Electric.');
        self::assertSame('Strings (pcs)', preg_replace('/\s+/', ' ', trim($fieldset->filter('legend')->text())));
        $min = $fieldset->filter(\sprintf('input[type="number"][step="1"][name="r[%s][min]"]', $this->stringsId()));
        $max = $fieldset->filter(\sprintf('input[type="number"][step="1"][name="r[%s][max]"]', $this->stringsId()));
        self::assertCount(1, $min);
        self::assertCount(1, $max);
        self::assertSame('', (string) $min->attr('value'));
        self::assertSame('', (string) $max->attr('value'));
        self::assertSame('3 listings, 6–12', trim($fieldset->filter('p')->text()));
        // S-12: the rules' bounds (4…12) are a write rule, never an HTML limit.
        foreach ([$min, $max] as $input) {
            self::assertNull($input->attr('min'));
            self::assertNull($input->attr('max'));
        }
    }

    public function testTheRangeInputsCarryTheSurvivingBounds(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->electric->id()->toString(), 'r' => [$this->stringsId() => ['min' => '6', 'max' => '8']]]));

        self::assertSame('6', $page->filter(\sprintf('input[name="r[%s][min]"]', $this->stringsId()))->attr('value'));
        self::assertSame('8', $page->filter(\sprintf('input[name="r[%s][max]"]', $this->stringsId()))->attr('value'));
    }

    public function testThePriceFieldsetOffersAnyCurrencyThenExactlyTheThreeCodesAndNotesTheHidingRule(): void
    {
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->electric->id()->toString(), 'price' => ['currency' => 'EUR', 'min' => '100', 'max' => '500']]));

        $price = $page->filter('form.search-facets fieldset.search-price');
        self::assertCount(1, $price);
        $options = $price->filter('select[name="price[currency]"] option');
        self::assertSame(['', 'USD', 'EUR', 'SEK'], $options->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('Any currency', trim($options->first()->text()));
        self::assertSame(['EUR'], $price->filter('option[selected]')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        foreach (['min' => '100', 'max' => '500'] as $bound => $value) {
            $input = $price->filter(\sprintf('input[name="price[%s]"]', $bound));
            self::assertCount(1, $input);
            self::assertSame('number', $input->attr('type'));
            self::assertSame('0', $input->attr('min'));
            self::assertSame('1', $input->attr('step'));
            self::assertSame($value, $input->attr('value'));
        }
        self::assertStringContainsString('hides listings priced in other currencies', $price->filter('p')->text());
    }

    public function testWithNoCategoryTheFormHoldsOnlyThePriceFieldset(): void
    {
        $page = $this->client->request('GET', '/browse');

        self::assertCount(1, $page->filter('form.search-facets'));
        self::assertCount(0, $page->filter('form.search-facets fieldset.search-facet'));
        self::assertCount(1, $page->filter('form.search-facets fieldset.search-price'));
        self::assertCount(0, $page->filter('input[name^="r["]'));
    }

    // ---------------------------------------------------------------- AC-21

    public function testSubmittingTheFormUntouchedAnswersTheUnfilteredTotal(): void
    {
        $uri = $this->browseUrl(['category' => $this->electric->id()->toString()]);
        $page = $this->client->request('GET', $uri);
        $unfiltered = $this->total($page);

        $submitted = $this->client->submit($page->selectButton('Apply filters')->form());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('4 listings', $unfiltered);
        self::assertSame($unfiltered, $this->total($submitted));
        self::assertStringContainsString('r%5B'.$this->stringsId().'%5D%5Bmin%5D=', (string) $this->client->getRequest()->getRequestUri(), 'Control: the blank range fields were really submitted.');
        self::assertCount(0, $submitted->filter('option[selected][value!=""]'));
    }

    public function testABlankMinimumWithAMaximumFiltersOnTheMaximumOnly(): void
    {
        $page = $this->client->request('GET', '/browse?category='.$this->electric->id()->toString().'&r['.$this->stringsId().'][min]=&r['.$this->stringsId().'][max]=6');

        self::assertSame('2 listings', $this->total($page));
        self::assertSame('', (string) $page->filter(\sprintf('input[name="r[%s][min]"]', $this->stringsId()))->attr('value'));
        self::assertSame('6', $page->filter(\sprintf('input[name="r[%s][max]"]', $this->stringsId()))->attr('value'));
    }

    // ---------------------------------------------------------------- AC-22

    /**
     * `{S}` is replaced with the real Strings attribute id, `{U}` with a well-formed unknown uuid.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileQueries(): iterable
    {
        $thirty = implode('&', array_map(static fn (int $i): string => \sprintf('r[018f5b2a-0000-7000-8000-%012d][min]=1', $i), range(1, 30)));

        yield 'r is a string' => ['r=x'];
        yield 'r is a list' => ['r[]=x'];
        yield 'an unknown attribute holding a string' => ['r[{U}]=x'];
        yield 'a real attribute holding a string' => ['r[{S}]=x'];
        yield 'min is a list' => ['r[{S}][min][]=1'];
        yield 'max is a list' => ['r[{S}][max][]=1'];
        yield 'NUL in a bound' => ['r[{S}][min]=%00'];
        yield 'invalid UTF-8 in a bound' => ['r[{S}][min]=%FF%FE'];
        yield 'invalid UTF-8 in a key and a value' => ['r[%FF%FE][min]=%FF%FE'];
        yield 'a bound that overflows int64' => ['r[{S}][min]=99999999999999999999'];
        yield 'a decimal bound' => ['r[{S}][max]=6.5'];
        yield 'thirty unknown attributes' => [$thirty];
        yield 'price is a string' => ['price=x'];
        yield 'price is a list' => ['price[]=EUR'];
        yield 'currency is a list' => ['price[currency][]=EUR'];
        yield 'a removed currency with bounds' => ['price[currency]=UAH&price[min]=1'];
        yield 'a negative price bound without currency' => ['price[min]=-5'];
        yield 'a price bound of eight digits without currency' => ['price[min]=99999999'];
        yield 'invalid UTF-8 in price' => ['price[currency]=%FF&price[min]=%FF'];
    }

    #[DataProvider('hostileQueries')]
    public function testAHostileRangeOrPriceParameterIsA200AndTheDroppedPartsAreIgnored(string $hostile): void
    {
        $category = $this->electric->id()->toString();
        $baseline = $this->mainOf($this->client->request('GET', $this->browseUrl(['category' => $category])));
        self::assertResponseStatusCodeSame(200);

        $page = $this->client->request('GET', '/browse?category='.$category.'&'.str_replace(['{S}', '{U}'], [$this->stringsId(), '018f5b2a-0000-7000-8000-0000000000aa'], $hostile));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($baseline, $this->mainOf($page), 'Everything dropped, so the page is the unfiltered page.');
    }

    public function testAHostilePriceBoundBesideAGoodCurrencyKeepsTheCurrencyOnly(): void
    {
        $category = $this->electric->id()->toString();
        $good = $this->mainOf($this->client->request('GET', $this->browseUrl(['category' => $category, 'price' => ['currency' => 'EUR']])));

        $page = $this->client->request('GET', '/browse?category='.$category.'&price[currency]=EUR&price[min]=-5&price[max]=99999999');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($good, $this->mainOf($page));
    }

    // ---------------------------------------------------------------- AC-23

    public function testTheGetSendsAtMostFivePlusOneStatementPerSelectionOfAnyKindAndAPriceAddsNone(): void
    {
        $category = $this->electric->id()->toString();
        $range = [$this->stringsId() => ['min' => '6', 'max' => null]];
        $brand = [$this->brand->id()->toString() => [$this->optionIdOf($this->brand, 'Gibson')]];
        $price = ['currency' => 'EUR', 'min' => '1', 'max' => '5000'];

        $cases = [
            [1, ['category' => $category, 'r' => $range]],
            [2, ['category' => $category, 'r' => $range, 'f' => $brand]],
        ];
        foreach ($cases as [$k, $query]) {
            $without = $this->recordedGet($this->browseUrl($query));
            self::assertResponseStatusCodeSame(200);
            $with = $this->recordedGet($this->browseUrl($query + ['price' => $price]));
            self::assertResponseStatusCodeSame(200);

            self::assertLessThanOrEqual(5 + $k, \count($without), "k = $k, statements:\n".implode("\n", $without));
            self::assertCount(\count($without), $with, 'A price filter must add no statement.');
            foreach ([...$without, ...$with] as $sql) {
                self::assertSame(0, preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $sql), 'A write statement: '.$sql);
                self::assertStringNotContainsString('seller_id', $sql);
            }
        }
    }

    public function testNumberFacetsAddNoStatement(): void
    {
        $electric = $this->recordedGet($this->browseUrl(['category' => $this->electric->id()->toString()]));
        // Same schema depth, no number facet: Guitars. Electric has Strings in addition and must not cost more.
        $guitars = $this->recordedGet($this->browseUrl(['category' => $this->guitars->id()->toString()]));

        self::assertCount(\count($guitars), $electric);
    }

    public function testNoCategoryWithAPriceFilterSendsAtMostThreeStatements(): void
    {
        $sql = $this->recordedGet($this->browseUrl(['price' => ['currency' => 'SEK', 'min' => '1', 'max' => '1000']]));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('price_currency', implode("\n", $sql), 'The recorder saw no price predicate, so the bound proves nothing.');
        self::assertLessThanOrEqual(3, \count($sql), "Statements:\n".implode("\n", $sql));
    }

    // ---------------------------------------------------------------- AC-24

    /**
     * Nineteen more published Electric listings holding Strings 6 and priced in EUR, so that
     * `r[Strings][min]=6` and EUR have 21 hits (N6a, N6b and these): two pages.
     */
    private function nineteenMore(): void
    {
        $seller = UserFactory::createOne();
        for ($i = 0; $i < 19; ++$i) {
            ListingFactory::new()->soldBy($seller)->inCategory($this->electric)->withValues($this->rangeElectricValues($this->optionIdOf($this->brand, 'Fender')) + [$this->stringsId() => '6'])->published()->create();
        }
    }

    public function testPagerBreadcrumbAndChildLinksCarryTheSurvivingRangeAndPrice(): void
    {
        $this->nineteenMore();
        $r = [$this->stringsId() => ['min' => '6', 'max' => null]];
        $price = ['currency' => 'EUR', 'min' => null, 'max' => null];
        $carried = static fn (array $query): array => array_intersect_key($query, ['r' => 1, 'price' => 1]);
        $page = $this->client->request('GET', $this->browseUrl(['category' => $this->electric->id()->toString(), 'r' => $r, 'price' => $price]));
        self::assertSame('21 listings', $this->total($page));

        $next = $this->queryOf((string) $page->filter('nav.search-pager a[rel="next"]')->attr('href'));
        self::assertSame('2', $next['page']);
        self::assertSame(['r' => [$this->stringsId() => ['min' => '6']], 'price' => ['currency' => 'EUR']], $carried($next), 'Blank bounds are the controller\'s to drop or keep; the surviving ones must be there.');

        $crumb = $page->filter('nav.search-breadcrumb a')->reduce(static fn (Crawler $a): bool => 'Instruments' === trim($a->text()));
        self::assertSame(['r', 'price'], array_keys($carried($this->queryOf((string) $crumb->attr('href')))));

        // Electric has no children: a child link is proved from Guitars' side below (range dropped, price kept).
    }

    public function testFromElectricToGuitarsTheRangeIsDroppedAndThePriceKept(): void
    {
        $r = [$this->stringsId() => ['min' => '6', 'max' => null]];
        $at = $this->client->request('GET', $this->browseUrl(['category' => $this->electric->id()->toString(), 'r' => $r, 'price' => ['currency' => 'EUR']]));
        $guitarsLink = $at->filter('nav.search-breadcrumb a')->reduce(static fn (Crawler $a): bool => 'Guitars' === trim($a->text()));
        self::assertArrayHasKey('r', $this->queryOf((string) $guitarsLink->attr('href')), 'Control: the link still carries the range.');

        $atGuitars = $this->client->click($guitarsLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $atGuitars->filter('input[name^="r["]'));
        self::assertSame(['EUR'], $atGuitars->filter('option[selected]')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('3 listings', $this->total($atGuitars), 'The price filter narrows (EUR: N6a, N6b, N0); the dropped range does not.');
        foreach ($atGuitars->filter('main a')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
            $query = $this->queryOf($href);
            self::assertArrayNotHasKey('r', $query, 'A link on the page still carries the dropped range: '.$href);
        }
        $child = $atGuitars->filter('ul.search-categories a')->first();
        self::assertSame(['currency' => 'EUR'], $this->queryOf((string) $child->attr('href'))['price']);
    }

    public function testClearFiltersClearsTheRangeTheSelectionAndThePrice(): void
    {
        $category = $this->electric->id()->toString();
        $page = $this->client->request('GET', $this->browseUrl([
            'category' => $category,
            'r' => [$this->stringsId() => ['min' => '6', 'max' => null]],
            'f' => [$this->brand->id()->toString() => [$this->optionIdOf($this->brand, 'Gibson')]],
            'price' => ['currency' => 'EUR'],
        ]));

        $clear = $page->filter('form.search-facets a')->last();

        self::assertSame('Clear filters', trim($clear->text()));
        self::assertSame($this->browseUrl(['category' => $category]), $clear->attr('href'));
    }
}
