<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheFacetFixture;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsListingPages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * search-ui AC-1 and AC-3 … AC-10: the page the `search` controller reads. Fixture is the lifted one
 * (`BuildsTheFacetFixture`): at Guitars, four published listings; no listing is priced in SEK.
 */
final class BrowseUiTest extends WebTestCase
{
    use BuildsTheFacetFixture;
    use ClearsRateLimiters;
    use ReadsListingPages;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
        $this->buildTheFacetFixture();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimiterPool();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $query
     */
    private function browse(array $query = []): Crawler
    {
        return $this->client->request('GET', '/browse'.([] === $query ? '' : '?'.http_build_query($query)));
    }

    private function guitarsId(): string
    {
        return $this->guitars->id()->toString();
    }

    private function brandId(): string
    {
        return $this->brand->id()->toString();
    }

    /**
     * @return array<int|string, mixed> the decoded query string of a link
     */
    private function queryOf(string $href): array
    {
        parse_str((string) parse_url(html_entity_decode($href), \PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return array<int|string, mixed> the query `main.search-browse` says is this search
     */
    private function searchUrlOf(Crawler $page): array
    {
        return $this->queryOf((string) $page->filter('main.search-browse')->attr('data-search-url'));
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

    /**
     * @return array<string, mixed>
     */
    private function zeroResultQuery(): array
    {
        return ['category' => $this->guitarsId(), 'f' => [$this->brandId() => [$this->optionIdOf($this->brand, 'Fender')]], 'price' => ['currency' => 'SEK']];
    }

    // ---------------------------------------------------------------- AC-1

    /**
     * @return array<string, array{string}>
     */
    public static function urls(): array
    {
        return ['category page' => ['category'], '0-result page' => ['zero'], 'text search' => ['text']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urls')]
    public function testTheControllersFetchGetsTheSameBodyAsANavigation(string $which): void
    {
        $uri = '/browse?'.http_build_query(match ($which) {
            'category' => ['category' => $this->guitarsId()],
            'zero' => $this->zeroResultQuery(),
            default => ['q' => 'fender'],
        });

        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document']);
        self::assertResponseStatusCodeSame(200);
        $navigation = (string) $this->client->getResponse()->getContent();

        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'text/html', 'HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty']);
        self::assertResponseStatusCodeSame(200);
        $fetched = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('<main class="search-browse"', $fetched);
        self::assertSame($this->normalised($navigation, []), $this->normalised($fetched, []));
        self::assertSame((new Crawler($navigation))->filter('main.search-browse')->outerHtml(), (new Crawler($fetched))->filter('main.search-browse')->outerHtml());
    }

    // ---------------------------------------------------------------- AC-3

    public function testTheSearchUrlHoldsTheSurvivingQueryAndNothingHostile(): void
    {
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $page = $this->client->request('GET', '/browse?'.http_build_query(['category' => $this->guitarsId(), 'f' => [$this->brandId() => [$fender]]]).'&f[x]=1&junk=1&q=');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['category' => $this->guitarsId(), 'f' => [$this->brandId() => [$fender]]], $this->searchUrlOf($page));
    }

    public function testTheSearchUrlCarriesPageOnlyFromPageTwo(): void
    {
        [$category, $finish, $gloss] = $this->twentyOneGlossy();
        $selection = ['category' => $category, 'f' => [$finish => [$gloss]]];

        self::assertSame($selection, $this->searchUrlOf($this->browse($selection)));
        self::assertSame($selection + ['page' => '2'], $this->searchUrlOf($this->browse($selection + ['page' => 2])));
    }

    public function testTheSearchUrlKeepsTextAndPrice(): void
    {
        $page = $this->browse(['q' => 'fender', 'price' => ['currency' => 'EUR', 'min' => '10']]);

        self::assertSame(['price' => ['currency' => 'EUR', 'min' => '10'], 'q' => 'fender'], $this->searchUrlOf($page));
    }

    // ---------------------------------------------------------------- AC-4

    public function testTheControllerWrapsMainAndTheStatusLineIsOutsideItAndEmpty(): void
    {
        $page = $this->browse(['category' => $this->guitarsId()]);

        self::assertCount(1, $page->filter('[data-controller~="search"]'));
        self::assertCount(1, $page->filter('[data-controller~="search"] main.search-browse'));
        $status = $page->filter('[role="status"][aria-live="polite"][data-search-target="status"]');
        self::assertCount(1, $status);
        self::assertCount(1, $page->filter('[data-controller~="search"] [role="status"]'));
        self::assertCount(0, $page->filter('main [role="status"]'), 'Inside <main> the status line would be swapped away.');
        self::assertSame('', trim($status->text()));
    }

    // ---------------------------------------------------------------- AC-5

    public function testEveryControlHasAUniqueIdAndAnAccessibleName(): void
    {
        $page = $this->browse(['category' => $this->guitarsId()]);

        $controls = $page->filter('form.search-facets input, form.search-facets select');
        self::assertGreaterThan(5, \count($controls));
        $ids = $controls->each(static fn (Crawler $c): string => (string) $c->attr('id'));
        self::assertNotContains('', $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'ids are unique');

        foreach ($controls as $node) {
            $control = new Crawler($node);
            if ('hidden' === $control->attr('type')) {
                continue;
            }
            $named = \count($control->ancestors()->filter('label')) > 0 || \count($page->filter('label[for="'.$control->attr('id').'"]')) > 0;
            self::assertTrue($named, 'No label for #'.$control->attr('id'));
        }
        self::assertCount(1, $page->filter('input#search-q[name="q"]'));
        self::assertCount(1, $page->filter('select#search-price-currency'));
        self::assertCount(1, $page->filter('input#search-price-min'));
        self::assertCount(1, $page->filter('input#search-price-max'));
    }

    public function testACheckboxHasTheSameIdWithAndWithoutASecondSelection(): void
    {
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $gibson = $this->optionIdOf($this->brand, 'Gibson');
        $expected = \sprintf('search-f-%s-%s', $this->brandId(), $fender);

        $alone = $this->browse(['category' => $this->guitarsId()]);
        $with = $this->browse(['category' => $this->guitarsId(), 'f' => [$this->brandId() => [$gibson]]]);

        foreach ([$alone, $with] as $page) {
            self::assertSame($fender, $page->filter('input#'.$expected)->attr('value'));
        }
    }

    // ---------------------------------------------------------------- AC-6, AC-7

    public function testTheHeadingAndResultsAreFocusTargetsAndSkippableTo(): void
    {
        $html = (string) $this->browse(['category' => $this->guitarsId()])->html();
        $page = new Crawler($html);

        self::assertSame('-1', $page->filter('h1#search-heading')->attr('tabindex'));
        self::assertCount(1, $page->filter('section.search-results#search-results'));
        self::assertCount(1, $page->filter('a[href="#search-results"]'));
        self::assertLessThan(strpos($html, '<form'), strpos($html, 'href="#search-results"'), 'The skip link precedes the form.');
    }

    public function testTheTotalIsOneElementHoldingTheCount(): void
    {
        $page = $this->browse(['category' => $this->guitarsId()]);

        self::assertCount(1, $page->filter('.search-total'));
        self::assertCount(1, $page->filter('section.search-results .search-total'));
        self::assertSame('4 listings', trim($page->filter('.search-total')->text()));
    }

    // ---------------------------------------------------------------- AC-8, AC-9

    public function testAZeroResultSearchWithNoCategoryOffersOnlyClearFilters(): void
    {
        $page = $this->browse(['price' => ['currency' => 'SEK']]);

        $empty = $page->filter('section.search-results section.search-empty');
        self::assertCount(1, $empty);
        self::assertSame('No listings found.', trim($empty->filter('h2')->text()));
        self::assertSame(['/browse'], $empty->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame(['Clear filters'], $empty->filter('a')->each(static fn (Crawler $a): string => trim($a->text())));
    }

    public function testAZeroResultSearchInACategoryOffersClearFiltersAndAllCategoriesWithoutCategoryBoundFilters(): void
    {
        $page = $this->browse($this->zeroResultQuery());

        $empty = $page->filter('section.search-empty');
        $clear = $empty->filter('a')->reduce(static fn (Crawler $a): bool => 'Clear filters' === trim($a->text()));
        $all = $empty->filter('a')->reduce(static fn (Crawler $a): bool => 'Search all categories' === trim($a->text()));
        self::assertCount(1, $clear);
        self::assertCount(1, $all);
        self::assertSame(['category' => $this->guitarsId()], $this->queryOf((string) $clear->attr('href')));
        self::assertSame(['price' => ['currency' => 'SEK']], $this->queryOf((string) $all->attr('href')), 'Brand is category-bound: dropped. Price survives.');
    }

    public function testAZeroResultTextSearchNamesTheEscapedTermsAndClearFiltersDropsThem(): void
    {
        $page = $this->browse(['category' => $this->guitarsId(), 'q' => '<b>qqqzzz</b>']);

        self::assertResponseStatusCodeSame(200);
        $empty = $page->filter('section.search-empty');
        self::assertCount(1, $empty);
        // The Domain keeps the surviving terms (markup characters become separators); the page names those.
        $surviving = (string) $page->filter('input#search-q')->attr('value');
        self::assertStringContainsString('qqqzzz', $surviving);
        self::assertStringContainsString('“'.$surviving.'”', $empty->text());
        self::assertStringNotContainsString('<b', $empty->html(), 'Never markup.');
        self::assertCount(0, $empty->filter('b'));
        $clear = $empty->filter('a')->reduce(static fn (Crawler $a): bool => 'Clear filters' === trim($a->text()));
        self::assertSame(['category' => $this->guitarsId()], $this->queryOf((string) $clear->attr('href')));
        $all = $empty->filter('a')->reduce(static fn (Crawler $a): bool => 'Search all categories' === trim($a->text()));
        self::assertSame(['q' => $surviving], $this->queryOf((string) $all->attr('href')), 'Text survives leaving the category.');
    }

    public function testAPagePastTheEndIsNotAZeroResultState(): void
    {
        [$category, $finish, $gloss] = $this->twentyOneGlossy();

        $page = $this->browse(['category' => $category, 'f' => [$finish => [$gloss]], 'page' => 3]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('section.search-empty'));
        self::assertCount(0, $page->filter('[data-search-alert-slot]'));
        self::assertStringContainsString('No listings found.', $page->filter('section.search-results')->text());
        self::assertCount(1, $page->filter('nav.search-pager a')->reduce(static fn (Crawler $a): bool => 'First page' === trim($a->text())));
    }

    public function testTheAlertSlotIsOnAZeroResultPageOnceInsideTheEmptyStateAndOnlyThere(): void
    {
        $zero = $this->browse($this->zeroResultQuery());
        self::assertCount(1, $zero->filter('[data-search-alert-slot]'));
        self::assertCount(1, $zero->filter('section.search-empty [data-search-alert-slot]'));
        self::assertSame('', trim($zero->filter('[data-search-alert-slot]')->html()), 'Nothing fills the slot yet.');

        self::assertCount(0, $this->browse(['category' => $this->guitarsId()])->filter('[data-search-alert-slot]'));
        self::assertCount(0, $this->browse()->filter('[data-search-alert-slot]'));
    }

    // ---------------------------------------------------------------- AC-10

    public function testEveryBrowsePageIsNoindexExactlyOnce(): void
    {
        [$category, $finish, $gloss] = $this->twentyOneGlossy();

        foreach ([
            ['category' => $this->guitarsId()],
            $this->zeroResultQuery(),
            ['q' => 'fender'],
            ['category' => $category, 'f' => [$finish => [$gloss]], 'page' => 2],
        ] as $query) {
            $page = $this->browse($query);
            self::assertResponseStatusCodeSame(200);
            self::assertCount(1, $page->filter('meta[name="robots"][content="noindex"]'));
            self::assertCount(1, $page->filter('meta[name="robots"]'));
        }
    }
}
