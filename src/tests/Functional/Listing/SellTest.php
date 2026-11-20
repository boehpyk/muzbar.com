<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Listing\Handler\ReviseListingHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Domain\Listing\ValueObject\Currency;
use App\Infrastructure\Catalog\Integration\CategoriesForListing;
use App\Infrastructure\Listing\Form\ListingFormType;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheValuesTree;
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
 * listing-attribute-values AC-18 … AC-27 (the "Details" tests, at the bottom) and listing-draft's AC-21, AC-23 … AC-29, AC-31 and AC-32 over HTTP against the real database (DAMA). One
 * `KernelBrowser` per test (the session is the sign-in; a second client would lose it) with
 * `disableReboot()` so recorded Sentry events survive the request. Rows are read back through DBAL.
 *
 * The fixture tree of the spec: Instruments > Guitars > Electric (leaf), Instruments > Bass (leaf).
 */
final class SellTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;
    private Connection $db;

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

    /** The categories only: the Details tests add `buildTheAttributes()` themselves. */
    private function buildTree(): void
    {
        $this->buildTheCategories();
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
     * @param array<string, string|array<string, string>> $fields
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

    // ============================================================================================
    // listing-attribute-values: the Details section (AC-18 … AC-27)
    // ============================================================================================

    private function detailsPath(Listing $draft): string
    {
        return '/sell/drafts/'.$draft->id()->toString().'/edit';
    }

    /**
     * Ann, signed in, the tree with its attributes, and draft D on Electric.
     *
     * @param bool $filled whether D already holds the five values of AC-7 (the attributes are built inside, so the inputs cannot be passed in)
     *
     * @return array{User, Listing}
     */
    private function annWithDraftD(bool $filled = false): array
    {
        $this->buildTree();
        $this->buildTheAttributes();
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->titled('Fender Precision V')->withValues($filled ? $this->allFiveElectricInputs() : [])->create();

        return [$ann, $draft];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueRows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id');
    }

    private function field(Attribute $attribute): string
    {
        return 'attributes_'.$attribute->id()->toString();
    }

    /**
     * @return list<string> the attribute ids of the Details fields, in page order
     */
    private function detailFieldIds(Crawler $page): array
    {
        return $page->filter('fieldset [name^="listing_form[attributes]["]')->each(static function (Crawler $node): string {
            \assert(1 === preg_match('/^listing_form\[attributes\]\[([0-9a-f-]{36})\]$/', (string) $node->attr('name'), $m));

            return $m[1];
        });
    }

    /**
     * @return list<array{string, string}> value and text of each option of a select
     */
    private function optionsOf(Crawler $page, string $field): array
    {
        return $page->filter('#listing_form_'.$field.' option')->each(static fn (Crawler $o): array => [(string) $o->attr('value'), trim($o->text())]);
    }

    private function labelOf(Crawler $page, string $field): string
    {
        return trim($page->filter('label[for="listing_form_'.$field.'"]')->text());
    }

    // ---------------------------------------------------------------- AC-18

    public function testTheEditPageAsksForExactlyTheEffectiveSchemaInRootFirstThenLabelOrder(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Details', trim($page->filter('fieldset legend')->text()));
        self::assertSame(
            array_map(static fn (Attribute $a): string => $a->id()->toString(), [$this->condition, $this->brand, $this->hasCase, $this->serial, $this->strings]),
            $this->detailFieldIds($page),
            'Root first (depth descending), then label: Condition, Brand, Has case, Serial, Strings.',
        );
        self::assertCount(0, $page->filter('#listing_form_'.$this->field($this->bassStrings)));
    }

    public function testEachDetailIsLabelledByItsAttributeAndANumbersUnitIsInParentheses(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertSame('Condition', $this->labelOf($page, $this->field($this->condition)));
        self::assertSame('Brand', $this->labelOf($page, $this->field($this->brand)));
        self::assertSame('Has case', $this->labelOf($page, $this->field($this->hasCase)));
        self::assertSame('Serial', $this->labelOf($page, $this->field($this->serial)));
        self::assertSame('Strings (pcs)', $this->labelOf($page, $this->field($this->strings)));
    }

    public function testRequiredAttributesAreFollowedByRequiredToPublishAndTheOthersAreNot(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        $help = static fn (string $field): string => $page->filter('#listing_form_'.$field)->ancestors()->first()->filter('.form-text, .help-text, small, p')->count() > 0
            ? trim($page->filter('#listing_form_'.$field)->ancestors()->first()->filter('.form-text, .help-text, small, p')->first()->text())
            : '';
        self::assertSame('required to publish', $help($this->field($this->condition)));
        self::assertSame('required to publish', $help($this->field($this->brand)));
        self::assertSame('', $help($this->field($this->strings)));
        self::assertSame('', $help($this->field($this->serial)));
    }

    public function testChoiceFieldsAreSelectsWithADashFirstThenTheOptionsInIdOrder(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertSame('select', $page->filter('#listing_form_'.$this->field($this->brand))->nodeName());
        self::assertSame(
            [['', '—'], [$this->optionIdOf($this->brand, 'Fender'), 'Fender'], [$this->optionIdOf($this->brand, 'Gibson'), 'Gibson']],
            $this->optionsOf($page, $this->field($this->brand)),
        );
        self::assertSame(
            [['', '—'], [$this->optionIdOf($this->condition, 'New'), 'New'], [$this->optionIdOf($this->condition, 'Used'), 'Used']],
            $this->optionsOf($page, $this->field($this->condition)),
        );
    }

    public function testNumberAndTextFieldsAreTextInputsAndABooleanIsADashYesNoSelect(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        foreach ([$this->strings, $this->serial] as $attribute) {
            $node = $page->filter('#listing_form_'.$this->field($attribute));
            self::assertSame('input', $node->nodeName());
            self::assertSame('text', $node->attr('type'));
        }
        self::assertSame('select', $page->filter('#listing_form_'.$this->field($this->hasCase))->nodeName());
        self::assertSame([['', '—'], ['yes', 'Yes'], ['no', 'No']], $this->optionsOf($page, $this->field($this->hasCase)));
    }

    public function testTheDetailsArePrefilledFromTheStoredValues(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertSame($this->optionIdOf($this->condition, 'Used'), $this->valueOf($page, $this->field($this->condition)));
        self::assertSame($this->optionIdOf($this->brand, 'Fender'), $this->valueOf($page, $this->field($this->brand)));
        self::assertSame('6', $this->valueOf($page, $this->field($this->strings)));
        self::assertSame('yes', $this->valueOf($page, $this->field($this->hasCase)));
        self::assertSame('SN-1', $this->valueOf($page, $this->field($this->serial)));
    }

    public function testADraftWithNoStoredValuesShowsEmptyDetails(): void
    {
        [, $draft] = $this->annWithDraftD();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        foreach ([$this->condition, $this->brand, $this->strings, $this->hasCase, $this->serial] as $attribute) {
            self::assertSame('', $this->valueOf($page, $this->field($attribute)), $attribute->code()->toString());
        }
    }

    public function testADraftOnACategoryWithNoAttributesShowsNoDetailsSection(): void
    {
        $this->buildTree();
        $this->buildTheAttributes();
        $ann = $this->signIn();
        $lonely = CategoryFactory::createOne();
        $draft = $this->draftOf($ann, $lonely);

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('fieldset'));
        self::assertStringNotContainsString('Details', $page->filter('form')->text());
    }

    // ---------------------------------------------------------------- AC-19 (the Phase 1 gate, previewed)

    public function testANewAttributeDefinedOnTheRootAppearsOnTheEditPageWithNoDeployAndNoNewRow(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $before = $this->valueRows();
        self::assertCount(5, $this->detailFieldIds($this->client->request('GET', $this->detailsPath($draft))), 'Sanity: five details before the define.');

        $container = self::getContainer();
        $handler = $container->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $handler);
        $id = $handler(new DefineAttribute($this->instruments->id()->toString(), 'choice', 'Fretboard wood', 'fretboard-wood', false, optionLabels: ['Rosewood', 'Maple']))->toString();

        $page = $this->client->request('GET', $this->detailsPath($draft));

        self::assertSame('Fretboard wood', $this->labelOf($page, 'attributes_'.$id));
        self::assertSame(['—', 'Rosewood', 'Maple'], array_column($this->optionsOf($page, 'attributes_'.$id), 1));
        self::assertSame('', $this->valueOf($page, 'attributes_'.$id));
        self::assertCount(6, $this->detailFieldIds($page));
        self::assertSame($before, $this->valueRows());
    }

    // ---------------------------------------------------------------- AC-20

    public function testTheCreatePageStillShowsNoDetailsEvenThoughCategoriesHaveAttributes(): void
    {
        $this->buildTree();
        $this->buildTheAttributes();
        $this->signIn();

        $page = $this->client->request('GET', '/sell');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('fieldset'));
        self::assertCount(0, $page->filter('[name^="listing_form[attributes]"]'));
    }

    public function testPostingDetailsToTheCreateFormIsRefusedAsAnExtraField(): void
    {
        $this->buildTree();
        $this->buildTheAttributes();
        $this->signIn();

        $this->client->request('POST', '/sell', ['listing_form' => ['_token' => $this->token()] + $this->validFields() + ['attributes' => [$this->serial->id()->toString() => 'SN-1']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->listings());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-21

    /**
     * @param array<string, string> $details by attribute id
     *
     * @return array<string, string|array<string, string>>
     */
    private function electricFields(array $details): array
    {
        return $this->validFields() + ['attributes' => $details];
    }

    public function testSavingDetailsRedirectsToTheDraftsPageAndWritesTheFiveRows(): void
    {
        [, $draft] = $this->annWithDraftD();
        $path = $this->detailsPath($draft);

        $this->post($path, $this->electricFields($this->allFiveElectricInputs()), tokenFrom: $path);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/sell/drafts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved.');
        self::assertStringNotContainsString('details', (string) $this->client->getCrawler()->filter('.flash-success')->text());
        $rows = $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE listing_id = ?', [$draft->id()->toString()]);
        self::assertCount(5, $rows);
        $byAttribute = array_column($rows, null, 'attribute_id');
        self::assertSame($this->optionIdOf($this->condition, 'Used'), $byAttribute[$this->condition->id()->toString()]['option_id']);
        self::assertSame($this->optionIdOf($this->brand, 'Fender'), $byAttribute[$this->brand->id()->toString()]['option_id']);
        self::assertSame(6, $byAttribute[$this->strings->id()->toString()]['value_int']);
        self::assertTrue($byAttribute[$this->hasCase->id()->toString()]['value_bool']);
        self::assertSame('SN-1', $byAttribute[$this->serial->id()->toString()]['value_text']);
        $this->assertNothingReportedToSentry();
    }

    public function testBlankDetailsInTheFormClearTheStoredValues(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $path = $this->detailsPath($draft);
        $blank = array_map(static fn (string $_): string => '', $this->allFiveElectricInputs());

        $this->post($path, $this->electricFields($blank), tokenFrom: $path);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->valueRows());
    }

    public function testRefilingFromTheFormRedirectsBackToTheEditPageWithTheNoticeAndKeepsOnlyTheSharedDetail(): void
    {
        [, $draft] = $this->annWithDraftD();
        $path = $this->detailsPath($draft);

        $this->post($path, ['category' => $this->bass->id()->toString()] + $this->electricFields($this->allFiveElectricInputs()), tokenFrom: $path);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($path);
        $page = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. The details now follow “Bass”: check them below.');
        $rows = $this->valueRows();
        self::assertCount(1, $rows);
        self::assertSame($this->condition->id()->toString(), $rows[0]['attribute_id']);
        // The page it lands on is Bass's form, with the kept detail prefilled.
        self::assertSame([$this->condition->id()->toString(), $this->bassStrings->id()->toString()], $this->detailFieldIds($page));
        self::assertSame($this->optionIdOf($this->condition, 'Used'), $this->valueOf($page, $this->field($this->condition)));
    }

    // ---------------------------------------------------------------- AC-22 + AC-27

    /**
     * Each user-error row of the failure contract: a field, a posted value, the message under it.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function detailRefusals(): iterable
    {
        yield 'a tampered option' => ['brand', '018f5b2a-0000-7000-8000-00000000ffff', 'Choose one of the listed options.'];
        yield 'an option of another attribute' => ['brand', 'USED', 'Choose one of the listed options.'];
        yield 'a number that is not whole' => ['strings', '7.5', 'Enter a whole number, like 6.'];
        yield 'words for a number' => ['strings', 'six', 'Enter a whole number, like 6.'];
        yield 'a number above the bounds' => ['strings', '13', 'Enter a whole number from 4 to 12.'];
        yield 'a number below the bounds' => ['strings', '3', 'Enter a whole number from 4 to 12.'];
        yield 'a tampered boolean' => ['has_case', 'Yes', 'Choose Yes or No.'];
        yield 'a text of 201 characters' => ['serial', '2xx', 'Keep this to one line of up to 200 characters.'];
        yield 'a multi-line text' => ['serial', "line one\nline two", 'Keep this to one line of up to 200 characters.'];
        yield 'a text with a tab' => ['serial', "a\tb", 'Keep this to one line of up to 200 characters.'];
    }

    #[DataProvider('detailRefusals')]
    public function testEachDetailRefusalIs422WithItsMessageOnThatFieldKeepingEverySubmittedValueAndWritingNothing(string $which, string $raw, string $message): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $path = $this->detailsPath($draft);
        $key = ['brand' => $this->brand, 'strings' => $this->strings, 'has_case' => $this->hasCase, 'serial' => $this->serial][$which];
        $raw = match ($raw) {
            'USED' => $this->optionIdOf($this->condition, 'Used'),
            '2xx' => str_repeat('x', 201),
            default => $raw,
        };
        // Everything else is submitted *different* from what is stored, to prove the form keeps what was typed.
        $submitted = [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'New'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Gibson'),
            $this->strings->id()->toString() => '8',
            $this->hasCase->id()->toString() => 'no',
            $this->serial->id()->toString() => 'SN-2',
            $key->id()->toString() => $raw,
        ];
        $listings = $this->listings();
        $values = $this->valueRows();

        $page = $this->post($path, ['title' => 'Never saved'] + $this->electricFields($submitted), tokenFrom: $path);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->errorsOn($page, $this->field($key)));
        foreach ([$this->condition, $this->brand, $this->strings, $this->hasCase, $this->serial] as $attribute) {
            if ($attribute === $key) {
                continue;
            }
            self::assertSame([], $this->errorsOn($page, $this->field($attribute)), $attribute->code()->toString().' must not carry the error.');
            self::assertSame($submitted[$attribute->id()->toString()], $this->valueOf($page, $this->field($attribute)), $attribute->code()->toString().' lost its submitted value.');
        }
        self::assertSame('Never saved', $this->valueOf($page, 'title'));
        self::assertSame($listings, $this->listings());
        self::assertSame($values, $this->valueRows());
        $this->assertNothingReportedToSentry();
    }

    public function testNumbersWithOnlyAMinimumOrOnlyAMaximumSayWhichBoundWasBroken(): void
    {
        $this->buildTree();
        $this->buildTheAttributes();
        $atLeast = AttributeFactory::new()->inCategory($this->electric)->coded('at-least')->labelled('At least')->number(NumberRules::of(null, 4, null))->create();
        $atMost = AttributeFactory::new()->inCategory($this->electric)->coded('at-most')->labelled('At most')->number(NumberRules::of(null, null, 12))->create();
        $ann = $this->signIn();
        $draft = $this->draftOf($ann);
        $path = $this->detailsPath($draft);

        $page = $this->post($path, $this->electricFields([$atLeast->id()->toString() => '3', $atMost->id()->toString() => '13']), tokenFrom: $path);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Enter a whole number of at least 4.'], $this->errorsOn($page, $this->field($atLeast)));

        $page = $this->post($path, $this->electricFields([$atLeast->id()->toString() => '4', $atMost->id()->toString() => '13']), tokenFrom: $path);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Enter a whole number of at most 12.'], $this->errorsOn($page, $this->field($atMost)));
        self::assertSame([], $this->valueRows());
        $this->assertNothingReportedToSentry();
    }

    public function testAKeyForAnAttributeTheFormDoesNotHaveIsAnExtraFieldRefusedWith422AndNothingIsWritten(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $path = $this->detailsPath($draft);
        $values = $this->valueRows();

        foreach ([$this->bassStrings->id()->toString(), 'not-a-uuid'] as $key) {
            $page = $this->post($path, $this->electricFields($this->allFiveElectricInputs() + [$key => '4']), tokenFrom: $path);

            self::assertResponseStatusCodeSame(422, $key);
            self::assertGreaterThan(0, $page->filter('form li')->count(), 'The refusal must be visible on the form.');
        }

        self::assertSame($values, $this->valueRows());
        $this->assertNothingReportedToSentry();
    }

    /**
     * `NotInForce` is reachable only in the window between building the form and taking the lock
     * (an admin deleting the attribute meanwhile): the form is rebuilt from the schema on POST, so a
     * stale key is an extra field before the Domain sees it. The window is opened here by handing
     * the handler an adapter whose schema has gone empty. The handler is swapped, not the adapter:
     * the adapter is already built by then, and the container refuses to replace a built service. The
     * GET builds the handler too (it is a controller argument), so the swap comes before the token.
     */
    public function testADetailThatLeftTheSchemaUnderTheLockIsRefusedOnItsFieldAsNoLongerApplying(): void
    {
        [, $draft] = $this->annWithDraftD();
        $path = $this->detailsPath($draft);
        $real = self::getContainer()->get(CategoriesForListing::class);
        self::assertInstanceOf(Categories::class, $real);
        $repository = self::getContainer()->get(ListingRepository::class);
        self::assertInstanceOf(ListingRepository::class, $repository);
        self::getContainer()->set(ReviseListingHandler::class, new ReviseListingHandler($repository, new class($real) implements Categories {
            public function __construct(private readonly Categories $inner)
            {
            }

            public function holdSteady(\Closure $work): mixed
            {
                return $this->inner->holdSteady($work);
            }

            public function statusOf(CategoryId $id): CategoryStatus
            {
                return $this->inner->statusOf($id);
            }

            public function schemaOf(CategoryId $id): AttributeSchema
            {
                return AttributeSchema::none();
            }
        }));
        $token = $this->token($path);
        $listings = $this->listings();
        $values = $this->valueRows();

        $page = $this->post($path, ['title' => 'Never saved'] + $this->electricFields([$this->serial->id()->toString() => 'SN-9']), $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['This detail no longer applies. Reload the page.'], $this->errorsOn($page, $this->field($this->serial)));
        self::assertSame($listings, $this->listings());
        self::assertSame($values, $this->valueRows());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-23

    public function testSomeoneElsesDraftIsTheSame404WithOrWithoutDetailsAndItsRowsDoNotMove(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $this->signIn('bob@example.com');
        $values = $this->valueRows();
        $listings = $this->listings();
        $token = $this->token();
        $unknown = Uuid::v7()->toRfc4122();
        $ids = [$draft->id()->toString(), $unknown];

        // The debug page also prints this test's own call (its arguments), so each payload is
        // compared with itself: Ann's draft against an id that does not exist.
        foreach (['with details' => $this->electricFields($this->allFiveElectricInputs()), 'without details' => $this->validFields()] as $label => $fields) {
            $bodies = [];
            foreach (['Ann\'s draft' => $draft->id()->toString(), 'an unknown id' => $unknown] as $which => $id) {
                $this->client->request('POST', '/sell/drafts/'.$id.'/edit', ['listing_form' => ['_token' => $token] + $fields]);
                self::assertResponseStatusCodeSame(404, $label.', '.$which);
                $bodies[$which] = $this->normalised((string) $this->client->getResponse()->getContent(), $ids);
            }
            self::assertCount(1, array_unique($bodies), 'Posting '.$label.': the 404 bodies differ, so one of them leaks whether the draft exists.');
        }
        $this->client->request('GET', '/sell/drafts/'.$draft->id()->toString().'/edit');
        self::assertResponseStatusCodeSame(404);

        self::assertSame($values, $this->valueRows(), 'Bob changed Ann\'s details.');
        self::assertSame($listings, $this->listings());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-25

    /**
     * @return list<string> the SQL the edit page sent for its data, the security user refresh excluded
     */
    private function dataQueriesOfTheEditPage(Listing $draft): array
    {
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $this->detailsPath($draft));
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the counts prove nothing.');

        return array_values(array_filter($sql, static fn (string $q): bool => !str_contains($q, 'identity_user')));
    }

    public function testTheEditPageIssuesFiveDataQueriesWhetherItHasOneChoiceAttributeOrFour(): void
    {
        [$ann, $draft] = $this->annWithDraftD(filled: true);
        // Electric's effective schema already has two choices (condition, brand); take it to four.
        AttributeFactory::new()->inCategory($this->electric)->coded('finish')->choice(['Matte', 'Gloss'])->create();
        AttributeFactory::new()->inCategory($this->electric)->coded('body')->choice(['Alder', 'Ash', 'Mahogany'])->create();
        $four = $this->dataQueriesOfTheEditPage($draft);

        $lonely = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($lonely)->coded('only-choice')->choice(['A', 'B'])->create();
        $one = $this->dataQueriesOfTheEditPage($this->draftOf($ann, $lonely, 'One choice'));

        self::assertCount(5, $four, "Four choice attributes:\n".implode("\n", $four));
        self::assertCount(5, $one, "One choice attribute:\n".implode("\n", $one));
    }

    // ---------------------------------------------------------------- AC-26

    public function testNoSellPageChangesAnyTableOnGetEvenWithAttributesAndStoredValues(): void
    {
        [, $draft] = $this->annWithDraftD(filled: true);
        $before = $this->everything();
        self::assertNotSame([], $before['listing_attribute_value'], 'The snapshot has no values in it, so it proves little.');

        foreach (['/sell', '/sell/drafts', $this->detailsPath($draft)] as $path) {
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
