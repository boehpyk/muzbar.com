<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsListingPages;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * listing-publish AC-21 … AC-25 (and AC-12's HTTP half): the public page `GET /listings/{id}`, the
 * first page anyone but its author can see. Ann sells, Bob is another user; the fixture tree is the
 * spec's. Published D: "Fender Precision V", 1234.50 EUR, published at 2026-11-20 10:00:00 UTC, with
 * all five Electric details stored.
 */
final class PublicListingTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use ReadsListingPages;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;
    private Connection $db;
    private User $ann;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->buildTheValuesTree();
        $this->ann = $this->registerUserWithKnownCredentials('ann@example.com', self::PASSWORD, verified: true);
    }

    // ---------------------------------------------------------------- helpers

    private function signInAs(string $email): void
    {
        $this->signInThroughTheLoginForm($this->client, $email, self::PASSWORD);
    }

    private function bob(): User
    {
        return $this->registerUserWithKnownCredentials('bob@example.com', self::PASSWORD, verified: true);
    }

    /**
     * @return array<string, string>
     */
    private function requiredDetails(): array
    {
        return [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ];
    }

    private function publishedD(string $description = ''): Listing
    {
        $factory = ListingFactory::new()->soldBy($this->ann)->inCategory($this->electric)->titled('Fender Precision V')->pricedAt('1234.50')
            ->withValues($this->allFiveElectricInputs())->published();
        $listing = $factory->create();

        if ('' !== $description) {
            $this->db->executeStatement('UPDATE listing_listing SET description = ? WHERE id = ?', [$description, $listing->id()->toString()]);
        }

        return $listing;
    }

    private function url(Listing|string $listing): string
    {
        return '/listings/'.($listing instanceof Listing ? $listing->id()->toString() : $listing);
    }

    /**
     * @return list<array{string, string}> each `<dt>`/`<dd>` pair of the Details list
     */
    private function details(Crawler $page): array
    {
        $labels = $page->filter('dl.listing-details dt')->each(static fn (Crawler $n): string => trim($n->text()));
        $values = $page->filter('dl.listing-details dd')->each(static fn (Crawler $n): string => trim($n->text()));
        self::assertCount(\count($labels), $values);

        return array_map(static fn (string $label, string $value): array => [$label, $value], $labels, $values);
    }

    /**
     * @return list<string> every SQL statement the GET sent
     */
    private function recordedGet(string $path): array
    {
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $path);
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        return $sql;
    }

    // ---------------------------------------------------------------- AC-21

    public function testAnAnonymousVisitorSeesTheTitlePriceCategoryPathAndPublicationDate(): void
    {
        $listing = $this->publishedD();

        $page = $this->client->request('GET', $this->url($listing));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Fender Precision V', trim($page->filter('article.listing h1')->text()));
        self::assertSame('1234.50 EUR', trim($page->filter('p.listing-price')->text()));
        self::assertSame('Instruments › Guitars › Electric', trim($page->filter('p.listing-category')->text()));
        self::assertSame('Listed on 20 Nov 2026', trim($page->filter('p.listing-published')->text()));
        self::assertStringStartsWith('2026-11-20T10:00:00', (string) $page->filter('p.listing-published time')->attr('datetime'));
    }

    public function testDetailsListEveryStoredValueInSchemaOrderWithLabelsUnitsAndYesNo(): void
    {
        $listing = $this->publishedD();

        $page = $this->client->request('GET', $this->url($listing));

        self::assertSame('Details', trim($page->filter('section.listing-details-section h2')->text()));
        self::assertSame(
            [['Condition', 'Used'], ['Brand', 'Fender'], ['Has case', 'Yes'], ['Serial', 'SN-1'], ['Strings', '6 pcs']],
            $this->details($page),
            'effectiveFor() order (root first, then label): a choice shows its option label, a number its unit, a boolean Yes/No, text as stored.',
        );
    }

    public function testAnAttributeWithNoValueIsOmittedFromDetails(): void
    {
        $listing = ListingFactory::new()->soldBy($this->ann)->inCategory($this->electric)->withValues($this->requiredDetails())->published()->create();

        $page = $this->client->request('GET', $this->url($listing));

        self::assertSame([['Condition', 'Used'], ['Brand', 'Fender']], $this->details($page));
    }

    public function testAListingWithNoStoredValuesHasNoDetailsSection(): void
    {
        $lonely = CategoryFactory::createOne();
        $listing = ListingFactory::new()->soldBy($this->ann)->inCategory($lonely)->published()->create();

        $page = $this->client->request('GET', $this->url($listing));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('section.listing-details-section'));
        self::assertCount(0, $page->filter('dl.listing-details'));
    }

    public function testTheDescriptionKeepsItsLineBreaksAndEscapesHtml(): void
    {
        $listing = $this->publishedD("Line one\nLine two <script>alert(1)</script>");

        $page = $this->client->request('GET', $this->url($listing));

        $description = $page->filter('div.listing-description');
        self::assertCount(1, $description);
        $html = $description->html();
        self::assertMatchesRegularExpression('#Line one<br\s*/?>\s*Line two#', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertCount(0, $description->filter('script'));
        self::assertSame('Line one Line two <script>alert(1)</script>', preg_replace('/\s+/', ' ', $description->text()));
    }

    public function testAnEmptyDescriptionRendersNoDescriptionBlock(): void
    {
        $page = $this->client->request('GET', $this->url($this->publishedD()));

        self::assertCount(0, $page->filter('div.listing-description'));
    }

    public function testSignedInAsBobTheListingIsIdenticalToWhatAnAnonymousVisitorSees(): void
    {
        $listing = $this->publishedD("Mint\ncondition");
        $anonymous = $this->client->request('GET', $this->url($listing))->filter('article.listing')->outerHtml();
        $this->bob();
        $this->signInAs('bob@example.com');

        $asBob = $this->client->request('GET', $this->url($listing));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($anonymous, $asBob->filter('article.listing')->outerHtml());
    }

    // ---------------------------------------------------------------- AC-22

    public function testThePageContainsNothingFromTheSellersAccount(): void
    {
        $listing = $this->publishedD();
        $account = $this->db->fetchAssociative('SELECT * FROM identity_user WHERE id = ?', [$this->ann->id()->toString()]);
        self::assertIsArray($account);
        $secrets = ['ann@example.com', $this->ann->id()->toString()];
        foreach ($account as $value) {
            if (\is_string($value) && \strlen($value) >= 4) {
                $secrets[] = $value;
            }
        }
        self::assertGreaterThan(3, \count($secrets), 'The account row gave nothing to look for: the scan below would prove nothing.');

        $this->client->request('GET', $this->url($listing));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseStatusCodeSame(200);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testThePublicReadNeverNamesSellerIdAndTheSignedInSellerSeesNothingMoreEither(): void
    {
        $listing = $this->publishedD();

        $anonymous = $this->recordedGet($this->url($listing));
        self::assertResponseStatusCodeSame(200);
        $onListings = array_values(array_filter($anonymous, static fn (string $q): bool => str_contains($q, 'listing_listing')));
        self::assertNotSame([], $onListings, 'The recorder saw no listing query, so "never seller_id" would pass vacuously.');
        foreach ($anonymous as $query) {
            self::assertStringNotContainsString('seller_id', $query);
        }

        $this->signInAs('ann@example.com');
        $asSeller = $this->recordedGet($this->url($listing));
        self::assertResponseStatusCodeSame(200);
        foreach ($asSeller as $query) {
            if (str_contains($query, 'listing_')) {
                self::assertStringNotContainsString('seller_id', $query);
            }
        }
    }

    // ---------------------------------------------------------------- AC-23

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        yield 'anonymous' => ['anonymous'];
        yield 'bob' => ['bob@example.com'];
        yield 'ann, the seller' => ['ann@example.com'];
    }

    #[DataProvider('viewers')]
    public function testADraftAWithdrawnListingAnUnknownIdAndAMalformedIdAreOneIdentical404(string $viewer): void
    {
        $draft = ListingFactory::new()->soldBy($this->ann)->inCategory($this->electric)->create();
        $withdrawn = ListingFactory::new()->soldBy($this->ann)->inCategory($this->electric)->withValues($this->requiredDetails())->withdrawn()->create();
        $unknown = Uuid::v7()->toRfc4122();
        $this->bob();
        if ('anonymous' !== $viewer) {
            $this->signInAs($viewer);
        }
        $ids = [$draft->id()->toString(), $withdrawn->id()->toString(), $unknown, 'not-a-uuid'];

        $bodies = [];
        foreach ($ids as $id) {
            $this->client->request('GET', $this->url($id));
            self::assertResponseStatusCodeSame(404, $id);
            $bodies[] = $this->normalised((string) $this->client->getResponse()->getContent(), $ids);
        }

        self::assertCount(1, array_unique($bodies), 'The 404 bodies differ, so one of them leaks whether the listing exists.');
    }

    public function testAMalformedIdIssuesNoListingQuery(): void
    {
        $sql = $this->recordedGet($this->url('not-a-uuid'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], array_values(array_filter($sql, static fn (string $q): bool => str_contains($q, 'listing_'))));
    }

    public function testAWithdrawnListingIsPublicAgainOnlyOncePublishedAgain(): void
    {
        $withdrawn = ListingFactory::new()->soldBy($this->ann)->inCategory($this->electric)->withValues($this->requiredDetails())->withdrawn()->create();
        $this->client->request('GET', $this->url($withdrawn));
        self::assertResponseStatusCodeSame(404);

        $this->db->executeStatement("UPDATE listing_listing SET status = 'published' WHERE id = ?", [$withdrawn->id()->toString()]);

        $this->client->request('GET', $this->url($withdrawn));
        self::assertResponseStatusCodeSame(200);
    }

    // ---------------------------------------------------------------- AC-24

    /**
     * @return list<string> the SQL of the anonymous GET, which must be five statements or fewer
     */
    private function budgetOf(Listing $listing): array
    {
        $sql = $this->recordedGet($this->url($listing));
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the counts prove nothing.');
        self::assertLessThanOrEqual(5, \count($sql), "Queries:\n".implode("\n", $sql));

        return $sql;
    }

    private function inCategoryWithChoices(Category $category, int $choices): Listing
    {
        $values = [];
        for ($i = 1; $i <= $choices; ++$i) {
            $attribute = AttributeFactory::new()->inCategory($category)->coded('choice-'.$i)->labelled('Choice '.$i)->choice(['A'.$i, 'B'.$i])->create();
            $values[$attribute->id()->toString()] = $this->optionIdOf($attribute, 'B'.$i);
        }

        return ListingFactory::new()->soldBy($this->ann)->inCategory($category)->withValues($values)->published()->create();
    }

    public function testThePageIssuesTheSameFiveOrFewerQueriesWithOneChoiceAttributeAsWithFour(): void
    {
        $one = $this->budgetOf($this->inCategoryWithChoices(CategoryFactory::createOne(), 1));
        $four = $this->budgetOf($this->inCategoryWithChoices(CategoryFactory::createOne(), 4));

        self::assertSame(\count($one), \count($four), "One choice:\n".implode("\n", $one)."\nFour choices:\n".implode("\n", $four));
        self::assertCount(5, $four, 'listing, outline, effective schema, values, option labels.');
    }

    public function testTheOptionLabelQueryNamesOnlyTheStoredOptionAndNothingLoadsAWholeOptionList(): void
    {
        $category = CategoryFactory::createOne();
        $labels = array_map(static fn (int $i): string => 'Brand '.$i, range(1, 50));
        $brand = AttributeFactory::new()->inCategory($category)->coded('wide-brand')->labelled('Wide brand')->choice($labels)->create();
        self::assertSame(50, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute_option WHERE attribute_id = ?', [$brand->id()->toString()]));
        $listing = ListingFactory::new()->soldBy($this->ann)->inCategory($category)->withValues([$brand->id()->toString() => $this->optionIdOf($brand, 'Brand 37')])->published()->create();

        $sql = $this->budgetOf($listing);

        $labelQueries = array_values(array_filter($sql, static fn (string $q): bool => 1 === preg_match('/FROM catalog_attribute_option\s+WHERE id IN/i', $q)));
        self::assertCount(1, $labelQueries, "Queries:\n".implode("\n", $sql));
        self::assertSame(1, substr_count($labelQueries[0], '?'), 'One stored option id, one placeholder: '.$labelQueries[0]);
        self::assertSame([], array_values(array_filter($sql, static fn (string $q): bool => 1 === preg_match('/FROM catalog_attribute_option\s+WHERE attribute_id/i', $q))), 'Something loaded an attribute\'s whole option list.');
        self::assertSame([['Wide brand', 'Brand 37']], $this->details($this->client->getCrawler()));
    }

    // ---------------------------------------------------------------- AC-25

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function everything(): array
    {
        $snapshot = [];
        foreach ($this->db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
            self::assertIsString($table);
            $snapshot[$table] = $this->db->fetchAllAssociative(\sprintf('SELECT * FROM "%s" ORDER BY 1', $table));
        }
        self::assertNotSame([], $snapshot['listing_attribute_value'], 'The snapshot holds no values, so it proves little.');

        return $snapshot;
    }

    public function testThePublicPageYourListingsAndTheEditPageChangeNoTableOnGet(): void
    {
        $listing = $this->publishedD();
        $this->signInAs('ann@example.com');
        $before = $this->everything();

        foreach ([$this->url($listing), '/sell/drafts', '/sell/drafts/'.$listing->id()->toString().'/edit'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
        }

        self::assertSame($before, $this->everything());
    }

    // ---------------------------------------------------------------- AC-12 (HTTP half)

    public function testAnAdminTighteningTheSchemaDoesNotUnpublishAndTheListingStillShowsItsStoredValues(): void
    {
        $listing = $this->publishedD();
        $before = $this->client->request('GET', $this->url($listing))->filter('article.listing')->outerHtml();

        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->hasCase->id()->toString(), 'Has case', 'has-case', true));
        ($this->service(DefineAttributeHandler::class))(new DefineAttribute($this->instruments->id()->toString(), 'text', 'Colour', 'colour', true));
        ($this->service(EditAttributeHandler::class))(new EditAttribute($this->strings->id()->toString(), 'Strings', 'strings', false, 'pcs', 7, 12));

        $after = $this->client->request('GET', $this->url($listing));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($before, $after->filter('article.listing')->outerHtml(), 'Never retroactive: the page shows what was stored, 6 pcs included.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
