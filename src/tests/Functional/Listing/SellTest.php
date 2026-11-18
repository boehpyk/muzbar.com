<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\ValueObject\Currency;
use App\Infrastructure\Listing\Form\ListingFormType;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * AC-21, AC-23 … AC-29, AC-31 and AC-32 over HTTP against the real database (DAMA). One
 * `KernelBrowser` per test (the session is the sign-in; a second client would lose it) with
 * `disableReboot()` so recorded Sentry events survive the request. Rows are read back through DBAL.
 *
 * The fixture tree of the spec: Instruments > Guitars > Electric (leaf), Instruments > Bass (leaf).
 */
final class SellTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;
    private Connection $db;

    private Category $instruments;
    private Category $guitars;
    private Category $electric;
    private Category $bass;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    // ---------------------------------------------------------------- helpers

    private function buildTree(): void
    {
        $this->instruments = CategoryFactory::createOne(['name' => CategoryName::fromString('Instruments'), 'slug' => CategorySlug::fromString('instruments')]);
        $this->guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars'), 'slug' => CategorySlug::fromString('guitars')])->childOf($this->instruments)->create();
        $this->electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric'), 'slug' => CategorySlug::fromString('electric')])->childOf($this->guitars)->create();
        $this->bass = CategoryFactory::new(['name' => CategoryName::fromString('Bass'), 'slug' => CategorySlug::fromString('bass')])->childOf($this->instruments)->create();
    }

    private function user(string $email): User
    {
        return $this->registerUserWithKnownCredentials($email, self::PASSWORD, verified: true);
    }

    private function signIn(string $email = 'ann@example.com'): User
    {
        $user = $this->user($email);
        $this->signInThroughTheLoginForm($this->client, $email, self::PASSWORD);

        return $user;
    }

    private function draftOf(User $seller, ?Category $category = null, string $title = 'Fender Precision V'): Listing
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($category ?? $this->electric)->titled($title)->create();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listings(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id');
    }

    /**
     * @return array<string, string>
     */
    private function validFields(): array
    {
        return [
            'title' => 'Fender Precision V',
            'description' => 'Mint condition',
            'price' => '1234.50',
            'currency' => 'EUR',
            'category' => $this->electric->id()->toString(),
        ];
    }

    private function token(string $path = '/sell'): string
    {
        $token = $this->client->request('GET', $path)->filter('input[name="listing_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields, ?string $token = null, string $tokenFrom = '/sell'): Crawler
    {
        $fields['_token'] = $token ?? $this->token($tokenFrom);

        return $this->client->request('POST', $path, ['listing_form' => $fields]);
    }

    /**
     * @return list<string>
     */
    private function errorsOn(Crawler $page, string $field): array
    {
        return $page->filter('#listing_form_'.$field)->ancestors()->first()->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function valueOf(Crawler $page, string $field): string
    {
        $node = $page->filter('#listing_form_'.$field);
        if ('select' === $node->nodeName()) {
            return $node->filter('option[selected]')->count() > 0 ? (string) $node->filter('option[selected]')->attr('value') : '';
        }
        if ('textarea' === $node->nodeName()) {
            return trim($node->text());
        }

        return (string) $node->attr('value');
    }

    private function assertNothingReportedToSentry(): void
    {
        self::assertSame([], $this->sentryEvents(), 'A user error must not be logged at error (AC-32).');
    }

    // ---------------------------------------------------------------- AC-21 anonymous

    public function testAnAnonymousVisitorIsSentToLoginFromEverySellPage(): void
    {
        foreach (['/sell', '/sell/drafts', '/sell/drafts/'.Uuid::v7()->toRfc4122().'/edit', '/sell/drafts/whatever/edit'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseStatusCodeSame(302, $path);
            self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'), $path);
        }
    }

    public function testAnAnonymousPostToSellRedirectsToLoginAndWritesNothing(): void
    {
        $this->buildTree();

        $this->client->request('POST', '/sell', ['listing_form' => $this->validFields()]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->listings());
    }

    #[DataProvider('lookalikePaths')]
    public function testPathsThatOnlyStartWithSellAreNotProtectedAndAre404(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lookalikePaths(): iterable
    {
        yield '/sellers' => ['/sellers'];
        yield '/selling' => ['/selling'];
    }

    // ---------------------------------------------------------------- AC-23 intended action

    public function testFollowingSellGearThroughSignInLandsOnTheSellPage(): void
    {
        $this->buildTree();
        $this->user('ann@example.com');

        $this->client->request('GET', '/forgot-password');
        $this->client->clickLink('Sell Gear');
        $this->client->submitForm('Sign in', ['_username' => 'ann@example.com', '_password' => self::PASSWORD]);
        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSame('/sell', $this->client->getRequest()->getPathInfo());
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form input#listing_form_title');
    }

    // ---------------------------------------------------------------- AC-24 the form

    public function testTheFormOffersTheFiveFieldsTheCurrenciesAndOnlyLeavesWithTheirPaths(): void
    {
        $this->buildTree();
        $this->signIn();

        $page = $this->client->request('GET', '/sell');

        self::assertResponseStatusCodeSame(200);
        foreach (['title', 'description', 'price', 'currency', 'category'] as $field) {
            self::assertCount(1, $page->filter('#listing_form_'.$field), $field);
        }
        self::assertCount(0, $page->filter('[name="listing_form[seller]"]'));

        $currencies = $page->filter('#listing_form_currency option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertSame(array_map(static fn (Currency $c): string => $c->value, Currency::cases()), $currencies);
        self::assertSame('UAH', $this->valueOf($page, 'currency'));

        $options = $page->filter('#listing_form_category option')->each(static fn (Crawler $o): array => [(string) $o->attr('value'), trim($o->text())]);
        self::assertSame(
            [
                ['', 'Choose a category'],
                [$this->bass->id()->toString(), 'Instruments › Bass'],
                [$this->electric->id()->toString(), 'Instruments › Guitars › Electric'],
            ],
            $options,
            'Leaves only, labelled with their full path, in outline order.',
        );
    }

    public function testWithNoCategoriesThePageSaysSoAndShowsNoForm(): void
    {
        $this->signIn();

        $page = $this->client->request('GET', '/sell');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('p', "Listings can't be created yet: there are no categories.");
        self::assertCount(0, $page->filter('form'));
    }

    // ---------------------------------------------------------------- AC-25 create

    public function testAValidPostSavesADraftForTheSignedInUserAndRedirects303WithAFlash(): void
    {
        $this->buildTree();
        $ann = $this->signIn();

        $this->post('/sell', $this->validFields());

        self::assertResponseStatusCodeSame(303);
        $rows = $this->listings();
        self::assertCount(1, $rows);
        // listing-attribute-values (Q4, AC-20) changed the target on purpose: the new draft's edit
        // page, where the details its category asks for are filled in — no longer /sell/drafts.
        self::assertIsString($rows[0]['id']);
        self::assertResponseRedirects('/sell/drafts/'.$rows[0]['id'].'/edit');
        self::assertSame($ann->id()->toString(), $rows[0]['seller_id']);
        self::assertSame($this->electric->id()->toString(), $rows[0]['category_id']);
        self::assertSame(123450, $rows[0]['price_amount']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. Add the details below.');
        $this->assertNothingReportedToSentry();
    }

    public function testAPostedSellerFieldIsRejectedAsAnExtraFieldAndNothingIsWritten(): void
    {
        $this->buildTree();
        $this->signIn();
        $other = $this->user('bob@example.com');

        $this->post('/sell', $this->validFields() + ['seller' => $other->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->listings());
        $this->assertNothingReportedToSentry();
    }

    public function testABadCsrfTokenIsRefusedWith422AndNothingIsWritten(): void
    {
        $this->buildTree();
        $this->signIn();

        $this->post('/sell', $this->validFields(), 'not-the-token');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->listings());
    }

    // ---------------------------------------------------------------- AC-26 refusals

    /**
     * @return iterable<string, array{array<string, string>, string, string}> overrides, field, message
     */
    public static function refusals(): iterable
    {
        yield 'title too short' => [['title' => 'ab'], 'title', 'Give the listing a title of 3 to 120 characters, on one line.'];
        yield 'title too long' => [['title' => str_repeat('a', 121)], 'title', 'Give the listing a title of 3 to 120 characters, on one line.'];
        yield 'description too long' => [['description' => str_repeat('a', 5001)], 'description', 'Keep the description under 5000 characters, in plain text.'];
        yield 'price malformed' => [['price' => 'abc'], 'price', 'Enter a price like 1250 or 1250.50, up to 9 999 999.99.'];
        yield 'price zero' => [['price' => '0'], 'price', 'Enter a price like 1250 or 1250.50, up to 9 999 999.99.'];
        yield 'price too many decimals' => [['price' => '1.234'], 'price', 'Enter a price like 1250 or 1250.50, up to 9 999 999.99.'];
        yield 'price over the maximum' => [['price' => '10000000'], 'price', 'Enter a price like 1250 or 1250.50, up to 9 999 999.99.'];
        yield 'currency tampered' => [['currency' => 'GBP'], 'currency', ListingFormType::UNSUPPORTED_CURRENCY];
        yield 'category malformed' => [['category' => 'not-a-uuid'], 'category', ListingFormType::UNKNOWN_CATEGORY];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('refusals')]
    public function testEachUserErrorRerendersWith422TheMessageOnItsFieldAndKeepsEveryValue(array $overrides, string $field, string $message): void
    {
        $this->buildTree();
        $this->signIn();
        $fields = $overrides + $this->validFields();

        $page = $this->post('/sell', $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->errorsOn($page, $field));
        foreach (array_diff(['title', 'description', 'price', 'currency', 'category'], [$field]) as $other) {
            self::assertSame([], $this->errorsOn($page, $other), "Unexpected error on $other.");
        }
        foreach (['title', 'description', 'price'] as $name) {
            self::assertSame($fields[$name], $this->valueOf($page, $name), "$name did not keep its value.");
        }
        self::assertSame([], $this->listings());
        $this->assertNothingReportedToSentry();
    }

    /**
     * Bytes the database would truncate (NUL) or reject (SQLSTATE 22021) are a user error, not a
     * 500. Kept apart from the provider above only because HTML cannot carry them back: the
     * re-rendered field shows U+FFFD, so "keeps every value" cannot hold for the refused field.
     */
    #[TestWith(['title', "Gi\0tar Fender", 'Give the listing a title of 3 to 120 characters, on one line.'])]
    #[TestWith(['description', "ab\xC3", 'Keep the description under 5000 characters, in plain text.'])]
    public function testBytesTheDatabaseCannotStoreAreRefusedOnTheirFieldNotA500(string $field, string $value, string $message): void
    {
        $this->buildTree();
        $this->signIn();

        $page = $this->post('/sell', [$field => $value] + $this->validFields());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->errorsOn($page, $field));
        self::assertSame([], $this->listings());
        $this->assertNothingReportedToSentry();
    }

    public function testAnUnknownWellFormedCategoryIsRefusedOnTheCategoryField(): void
    {
        $this->buildTree();
        $this->signIn();

        $page = $this->post('/sell', ['category' => Uuid::v7()->toRfc4122()] + $this->validFields());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([ListingFormType::UNKNOWN_CATEGORY], $this->errorsOn($page, 'category'));
        self::assertSame([], $this->listings());
    }

    public function testACategoryThatIsNotALeafIsRefusedWithTheSubcategoriesMessage(): void
    {
        $this->buildTree();
        $this->signIn();

        $page = $this->post('/sell', ['category' => $this->guitars->id()->toString()] + $this->validFields());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['That category now has subcategories. Choose a more specific one.'], $this->errorsOn($page, 'category'));
        self::assertSame('Fender Precision V', $this->valueOf($page, 'title'));
        self::assertSame([], $this->listings());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-27 my drafts

    public function testMyDraftsListsOnlyMyDraftsNewestFirstWithPricePathAndEditLink(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        $bob = $this->user('bob@example.com');
        $first = $this->draftOf($ann, $this->electric, 'Older draft');
        $second = ListingFactory::new()->soldBy($ann)->inCategory($this->bass)->titled('Newer draft')->pricedAt('7', Currency::UAH)->create();
        $this->draftOf($bob, $this->bass, 'Bobs secret draft');

        $page = $this->client->request('GET', '/sell/drafts');

        self::assertResponseStatusCodeSame(200);
        $drafts = $page->filter('li.draft');
        self::assertCount(2, $drafts);
        self::assertSame('Newer draft', trim($drafts->eq(0)->filter('.draft-title')->text()));
        self::assertSame('7.00 UAH', trim($drafts->eq(0)->filter('.draft-price')->text()));
        self::assertSame('Instruments › Bass', trim($drafts->eq(0)->filter('.draft-category')->text()));
        self::assertSame('/sell/drafts/'.$second->id()->toString().'/edit', $drafts->eq(0)->selectLink('Edit')->attr('href'));
        self::assertSame('Older draft', trim($drafts->eq(1)->filter('.draft-title')->text()));
        self::assertSame('1234.50 EUR', trim($drafts->eq(1)->filter('.draft-price')->text()));
        self::assertSame('Instruments › Guitars › Electric', trim($drafts->eq(1)->filter('.draft-category')->text()));
        self::assertSame('/sell/drafts/'.$first->id()->toString().'/edit', $drafts->eq(1)->selectLink('Edit')->attr('href'));
        self::assertStringNotContainsString('Bobs secret draft', (string) $this->client->getResponse()->getContent());
    }

    public function testWithNoDraftsThePageSaysSoAndLinksToSell(): void
    {
        $this->signIn();

        $page = $this->client->request('GET', '/sell/drafts');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('p', 'You have no drafts yet.');
        self::assertCount(1, $page->filter('p a[href="/sell"]'));
        self::assertCount(0, $page->filter('li.draft'));
    }

    public function testTheDraftsPageIssuesTwoQueriesForItsDataHoweverManyDraftsThereAre(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        foreach ([$this->electric, $this->bass, $this->electric, $this->bass] as $i => $category) {
            $this->draftOf($ann, $category, 'Draft number '.$i);
        }
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', '/sell/drafts');
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        self::assertCount(4, $this->client->getCrawler()->filter('li.draft'));
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the counts below prove nothing.');
        $onListings = array_filter($sql, static fn (string $q): bool => str_contains($q, 'listing_listing'));
        $onCategories = array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_category'));
        self::assertCount(1, $onListings, "Queries on listing_listing:\n".implode("\n", $onListings));
        self::assertCount(1, $onCategories, "Queries on catalog_category:\n".implode("\n", $onCategories));
    }

    // ---------------------------------------------------------------- AC-28 edit

    public function testTheEditFormIsPrefilledWithThePriceAsADecimalString(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->bass)->titled('Fender Precision V')->pricedAt('1234.5', Currency::EUR)->create();

        $page = $this->client->request('GET', '/sell/drafts/'.$draft->id()->toString().'/edit');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Fender Precision V', $this->valueOf($page, 'title'));
        self::assertSame('1234.50', $this->valueOf($page, 'price'));
        self::assertSame('EUR', $this->valueOf($page, 'currency'));
        self::assertSame($this->bass->id()->toString(), $this->valueOf($page, 'category'));
    }

    public function testAValidEditRedirects303WithAFlashAndChangesTheRow(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        $draft = $this->draftOf($ann);
        $path = '/sell/drafts/'.$draft->id()->toString().'/edit';

        $this->post($path, ['title' => 'Changed title', 'price' => '99', 'currency' => 'UAH', 'category' => $this->bass->id()->toString(), 'description' => 'New'], tokenFrom: $path);

        self::assertResponseStatusCodeSame(303);
        // This edit re-files the draft (Electric → Bass), so since listing-attribute-values (AC-21)
        // it lands back on the edit page with the re-file notice — changed on purpose.
        self::assertResponseRedirects($path);
        $row = $this->db->fetchAssociative('SELECT * FROM listing_listing WHERE id = ?', [$draft->id()->toString()]);
        self::assertIsArray($row);
        self::assertSame('Changed title', $row['title']);
        self::assertSame(9900, $row['price_amount']);
        self::assertSame('UAH', $row['price_currency']);
        self::assertSame($this->bass->id()->toString(), $row['category_id']);
        self::assertSame($ann->id()->toString(), $row['seller_id']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. The details now follow “Bass”: check them below.');
    }

    public function testARefusedEditRendersThe422AndLeavesTheRowUnchanged(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        $draft = $this->draftOf($ann);
        $path = '/sell/drafts/'.$draft->id()->toString().'/edit';
        $before = $this->listings();

        $page = $this->post($path, ['title' => 'x'] + $this->validFields(), tokenFrom: $path);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Give the listing a title of 3 to 120 characters, on one line.'], $this->errorsOn($page, 'title'));
        self::assertSame($before, $this->listings());
    }

    // ---------------------------------------------------------------- AC-29 someone else's draft

    public function testSomeoneElsesDraftAndUnknownOrMalformedIdsAnswerTheSame404AndNothingChanges(): void
    {
        $this->buildTree();
        $ann = $this->user('ann@example.com');
        $draft = $this->draftOf($ann);
        $this->signIn('bob@example.com');
        $before = $this->listings();
        $unknown = Uuid::v7()->toRfc4122();
        $token = $this->token();

        $bodies = ['GET' => [], 'POST' => []];
        foreach ([
            'Bob GET Ann' => ['GET', $draft->id()->toString()],
            'Bob POST Ann' => ['POST', $draft->id()->toString()],
            'unknown GET' => ['GET', $unknown],
            'unknown POST' => ['POST', $unknown],
            'malformed GET' => ['GET', 'not-a-uuid'],
        ] as $label => [$method, $id]) {
            $this->client->request($method, '/sell/drafts/'.$id.'/edit', 'POST' === $method ? ['listing_form' => ['_token' => $token] + $this->validFields()] : []);
            self::assertResponseStatusCodeSame(404, $label);
            $bodies[$method][] = $this->normalised((string) $this->client->getResponse()->getContent(), [$draft->id()->toString(), $unknown, 'not-a-uuid']);
        }

        // The debug page also prints this test's own call (method and arguments), so GETs are
        // compared with GETs and POSTs with POSTs.
        self::assertCount(3, $bodies['GET']);
        self::assertCount(2, $bodies['POST']);
        self::assertCount(1, array_unique($bodies['GET']), 'The GET 404 bodies differ: one of them leaks whether the draft exists.');
        self::assertCount(1, array_unique($bodies['POST']), 'The POST 404 bodies differ: one of them leaks whether the draft exists.');
        self::assertSame($before, $this->listings(), 'Bob changed Ann\'s draft.');
        $this->assertNothingReportedToSentry();
    }

    /**
     * The test-env debug page embeds ids, line numbers and per-render dump ids; strip what varies
     * per request so what is left is what the page *says*.
     *
     * @param list<string> $ids
     */
    private function normalised(string $body, array $ids): string
    {
        $body = str_replace($ids, 'ID', $body);
        $body = preg_replace('#<(script|style)\b.*?</\1>#s', '', $body) ?? '';
        $body = preg_replace('/sf-dump-\d+|\d+/', 'N', $body) ?? '';

        return $body;
    }

    // ---------------------------------------------------------------- AC-31 no GET changes state

    public function testNoSellPageChangesAnyTableOnGet(): void
    {
        $this->buildTree();
        $ann = $this->signIn();
        $draft = $this->draftOf($ann);
        $before = $this->everything();

        foreach (['/sell', '/sell/drafts', '/sell/drafts/'.$draft->id()->toString().'/edit'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
        }

        self::assertSame($before, $this->everything());
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function everything(): array
    {
        $snapshot = [];
        foreach ($this->db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
            \assert(\is_string($table));
            $snapshot[$table] = $this->db->fetchAllAssociative(\sprintf('SELECT * FROM "%s" ORDER BY 1', $table));
        }
        self::assertArrayHasKey('listing_listing', $snapshot);
        self::assertNotSame([], $snapshot['listing_listing'], 'The snapshot has no draft in it, so it proves little.');

        return $snapshot;
    }
}
