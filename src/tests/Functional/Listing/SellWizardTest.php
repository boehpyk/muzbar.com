<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Event\ListingPublished;
use App\Infrastructure\Shared\Event\SymfonyDomainEventDispatcher;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * listing-wizard AC-11 … AC-18 and AC-21 over HTTP against the real database (DAMA): what the page's
 * real POST writes once the form can carry Details (`details_for`). One `KernelBrowser` per test with
 * `disableReboot()`; Ann is the seller. The tree is the values tree (Instruments > Guitars > Electric,
 * Instruments > Bass); Electric requires condition and brand, Bass requires condition.
 */
final class SellWizardTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';
    private const string FILL = 'Fill this in to publish.';

    private KernelBrowser $client;
    private Connection $db;
    private SpyDomainEventDispatcher $events;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        // Before the first request: a service that is already initialised cannot be replaced. It
        // targets the concrete class's id, never the port alias (alias references are rewritten at
        // compile time), and swallows every event, so no listener runs.
        $this->events = new SpyDomainEventDispatcher();
        self::getContainer()->set(SymfonyDomainEventDispatcher::class, $this->events);
        $this->buildTheValuesTree();
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(string $email = 'ann@example.com'): User
    {
        $user = $this->registerUserWithKnownCredentials($email, self::PASSWORD, verified: true);
        $this->signInThroughTheLoginForm($this->client, $email, self::PASSWORD);

        return $user;
    }

    private function id(Attribute|Category $thing): string
    {
        return $thing->id()->toString();
    }

    /** @return array<string, string> condition = Used, brand = Fender */
    private function electricRequired(): array
    {
        return [
            $this->id($this->condition) => $this->optionIdOf($this->condition, 'Used'),
            $this->id($this->brand) => $this->optionIdOf($this->brand, 'Fender'),
        ];
    }

    /** @return array<string, string> condition = Used */
    private function bassRequired(): array
    {
        return [$this->id($this->condition) => $this->optionIdOf($this->condition, 'Used')];
    }

    /**
     * @param array<string, string> $details
     * @param array<string, string> $overrides
     *
     * @return array<string, string|array<string, string>>
     */
    private function fields(string $category, string $detailsFor, array $details, array $overrides = []): array
    {
        $fields = $overrides + [
            'title' => 'Fender Precision V',
            'description' => 'Mint condition',
            'price' => '1234.50',
            'currency' => 'EUR',
            'category' => $category,
            'details_for' => $detailsFor,
        ];
        if ([] !== $details) {
            $fields['attributes'] = $details;
        }

        return $fields;
    }

    /** @param array<string, string|array<string, string>> $fields */
    private function postSell(array $fields, ?string $action = null): Crawler
    {
        $token = $this->client->request('GET', '/sell')->filter('input[name="listing_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return $this->client->request('POST', '/sell', ['listing_form' => $fields + ['_token' => $token]] + (null === $action ? [] : ['action' => $action]));
    }

    /** @param array<string, string|array<string, string>> $fields */
    private function postEdit(Listing $listing, array $fields, ?string $action = null): Crawler
    {
        $path = '/sell/drafts/'.$listing->id()->toString().'/edit';
        $token = $this->client->request('GET', $path)->filter('input[name="listing_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        return $this->client->request('POST', $path, ['listing_form' => $fields + ['_token' => $token]] + (null === $action ? [] : ['action' => $action]));
    }

    /** @return list<array<string, mixed>> */
    private function listings(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    private function valueRows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id');
    }

    /** @return array{id: string, category_id: string, status: string, published_at: ?string} */
    private function onlyListing(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, category_id, status, published_at FROM listing_listing');
        self::assertCount(1, $rows);
        $row = $rows[0];

        return [
            'id' => \is_string($row['id']) ? $row['id'] : self::fail('id'),
            'category_id' => \is_string($row['category_id']) ? $row['category_id'] : self::fail('category_id'),
            'status' => \is_string($row['status']) ? $row['status'] : self::fail('status'),
            'published_at' => \is_string($row['published_at']) ? $row['published_at'] : null,
        ];
    }

    /** @return list<string> sorted attribute ids holding a value for the listing */
    private function storedAttributes(string $listingId): array
    {
        /** @var list<string> $ids */
        $ids = $this->db->fetchFirstColumn('SELECT attribute_id FROM listing_attribute_value WHERE listing_id = ? ORDER BY attribute_id', [$listingId]);

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function errorsOn(Crawler $page, string $field): array
    {
        return $page->filter('#listing_form_'.$field)->ancestors()->first()->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function detailField(Attribute $attribute): string
    {
        return 'attributes_'.$this->id($attribute);
    }

    /**
     * @param list<array<string, mixed>> $listings
     * @param list<array<string, mixed>> $values
     */
    private function assertNothingWritten(array $listings, array $values): void
    {
        self::assertSame($listings, $this->listings(), 'No listing row may change.');
        self::assertSame($values, $this->valueRows(), 'No value row may change.');
    }

    /** @return list<object> the Listing events dispatched so far (sign-up events are not the point here) */
    private function listingEvents(): array
    {
        return array_values(array_filter($this->events->dispatched(), static fn (object $e): bool => str_starts_with($e::class, 'App\\Domain\\Listing\\')));
    }

    // ---------------------------------------------------------------- AC-11

    public function testSellPostWithDetailsAndSaveDraftPersistsTheDraftWithItsValuesAndRedirectsToItsEditPage(): void
    {
        $this->signIn();
        $details = $this->electricRequired() + [$this->id($this->strings) => '6'];

        $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $details), 'save');

        $row = $this->onlyListing();
        self::assertResponseRedirects('/sell/drafts/'.$row['id'].'/edit', 303);
        self::assertSame('draft', $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame($this->id($this->electric), $row['category_id']);
        self::assertSame($this->sorted(array_keys($details)), $this->storedAttributes($row['id']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved.');
    }

    // ---------------------------------------------------------------- AC-12

    public function testSellPostSaveAndPublishWithTheRequiredDetailsPublishesAndDispatchesListingPublishedOnce(): void
    {
        $this->signIn();

        $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired()), 'publish');

        $row = $this->onlyListing();
        self::assertResponseRedirects('/listings/'.$row['id'], 303);
        self::assertSame('published', $row['status']);
        self::assertNotNull($row['published_at']);
        self::assertSame($this->sorted(array_keys($this->electricRequired())), $this->storedAttributes($row['id']));
        $published = $this->listingEvents();
        self::assertCount(1, $published, 'Exactly one Listing event.');
        self::assertInstanceOf(ListingPublished::class, $published[0]);
        self::assertSame($row['id'], $published[0]->listingId()->toString());
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    // ---------------------------------------------------------------- AC-13

    public function testSaveAndPublishWithARequiredDetailEmptyIs422OnThatFieldAndWritesNothingAtAll(): void
    {
        $this->signIn();
        $listings = $this->listings();
        $values = $this->valueRows();
        $details = [$this->id($this->condition) => $this->optionIdOf($this->condition, 'Used'), $this->id($this->brand) => ''];

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $details), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->brand)));
        self::assertSame([], $this->errorsOn($page, $this->detailField($this->condition)));
        $this->assertNothingWritten($listings, $values);
        self::assertSame([], $this->listingEvents());
    }

    public function testTheSameInputWithSaveDraftIsAcceptedBecauseARequiredDetailBitesOnlyOnPublish(): void
    {
        $this->signIn();

        $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), [$this->id($this->brand) => '']), 'save');

        self::assertResponseStatusCodeSame(303);
        self::assertSame('draft', $this->onlyListing()['status']);
    }

    // ---------------------------------------------------------------- AC-14

    public function testAJsFreeFirstSubmitDraftsWithoutDetailsAndDoesNotPublishEvenOnSaveAndPublish(): void
    {
        $this->signIn();

        $this->postSell($this->fields($this->id($this->electric), '', []), 'publish');

        $row = $this->onlyListing();
        self::assertResponseRedirects('/sell/drafts/'.$row['id'].'/edit', 303);
        self::assertSame('draft', $row['status']);
        self::assertSame([], $this->storedAttributes($row['id']));
        self::assertSame([], $this->listingEvents());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. Add the details below.');
    }

    public function testDetailsRenderedForAnotherCategoryAreNotForwardedAndNothingIsPublished(): void
    {
        $this->signIn();
        // The page's Details were Bass's; the seller's category select now says Electric (a race).
        $bassDetails = $this->bassRequired() + [$this->id($this->bassStrings) => '4'];

        $this->postSell($this->fields($this->id($this->electric), $this->id($this->bass), $bassDetails), 'publish');

        $row = $this->onlyListing();
        self::assertResponseRedirects('/sell/drafts/'.$row['id'].'/edit', 303);
        self::assertSame('draft', $row['status']);
        self::assertSame($this->id($this->electric), $row['category_id']);
        self::assertSame([], $this->storedAttributes($row['id']), 'The Details belonged to another category: none forwarded.');
        self::assertSame([], $this->listingEvents());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. Add the details below.');
    }

    // ---------------------------------------------------------------- AC-15, AC-16

    private function annsElectricDraft(): Listing
    {
        $ann = $this->signIn();

        return ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->titled('Fender Precision V')->withValues($this->electricRequired() + [$this->id($this->serial) => 'SN-1'])->create();
    }

    public function testRefilingToBassWithBassDetailsBuildsTheFormForBassAndStoresTheValues(): void
    {
        $draft = $this->annsElectricDraft();
        $details = $this->bassRequired() + [$this->id($this->bassStrings) => '4'];

        $this->postEdit($draft, $this->fields($this->id($this->bass), $this->id($this->bass), $details), 'save');

        self::assertResponseRedirects('/sell/drafts/'.$draft->id()->toString().'/edit', 303);
        $row = $this->onlyListing();
        self::assertSame($this->id($this->bass), $row['category_id']);
        self::assertSame('draft', $row['status']);
        self::assertSame($this->sorted(array_keys($details)), $this->storedAttributes($draft->id()->toString()), 'Bass\'s values stored; Electric\'s dropped (L-10).');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved. The details now follow “Bass”: check them below.');
    }

    public function testRefilingWithTheNewCategorysDetailsAndSaveAndPublishPublishesAgainstTheNewSchema(): void
    {
        $draft = $this->annsElectricDraft();
        // Bass requires condition only: no brand is asked, none is given.
        $this->postEdit($draft, $this->fields($this->id($this->bass), $this->id($this->bass), $this->bassRequired()), 'publish');

        self::assertResponseRedirects('/listings/'.$draft->id()->toString(), 303);
        $row = $this->onlyListing();
        self::assertSame('published', $row['status']);
        self::assertSame($this->id($this->bass), $row['category_id']);
        self::assertCount(1, $this->listingEvents());
    }

    public function testPublishingAfterARefileRefusesAnEmptyRequiredDetailOfTheNewCategoryAndChangesNothing(): void
    {
        $draft = $this->annsElectricDraft();
        $listings = $this->listings();
        $values = $this->valueRows();

        $page = $this->postEdit($draft, $this->fields($this->id($this->bass), $this->id($this->bass), [$this->id($this->condition) => '']), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->condition)));
        $this->assertNothingWritten($listings, $values);
    }

    public function testRefilingWithTheOldCategorysDetailsKeepsTodaysBehaviourAndDoesNotPublish(): void
    {
        $draft = $this->annsElectricDraft();

        // JS-free: the page was rendered for Electric; the seller changed the select to Bass.
        $this->postEdit($draft, $this->fields($this->id($this->bass), $this->id($this->electric), $this->electricRequired() + [$this->id($this->serial) => 'SN-1']), 'publish');

        self::assertResponseRedirects('/sell/drafts/'.$draft->id()->toString().'/edit', 303);
        $row = $this->onlyListing();
        self::assertSame('draft', $row['status']);
        self::assertSame($this->id($this->bass), $row['category_id']);
        self::assertSame([$this->id($this->condition)], $this->storedAttributes($draft->id()->toString()), 'L-10 drops what Bass does not ask; the shared condition survives.');
        self::assertSame([], $this->listingEvents());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved, not published yet. The details now follow “Bass”');
    }

    public function testASaveOnTheUnchangedCategoryWithTheStoredDetailsStillWorks(): void
    {
        $draft = $this->annsElectricDraft();

        $this->postEdit($draft, $this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired() + [$this->id($this->strings) => '8']), 'save');

        self::assertResponseRedirects('/sell/drafts', 303);
        self::assertContains($this->id($this->strings), $this->storedAttributes($draft->id()->toString()));
        self::assertNotContains($this->id($this->serial), $this->storedAttributes($draft->id()->toString()), 'Blank = clear: serial was left off.');
    }

    // ---------------------------------------------------------------- AC-17

    /** @return iterable<string, array{string}> */
    public static function tamperedDetailsFor(): iterable
    {
        yield 'unknown uuid' => ['0198a000-0000-7000-8000-000000000001'];
        yield 'malformed' => ["x' OR 1=1 --"];
        yield 'a branch' => ['@branch'];
        yield 'not a uuid' => ['42'];
    }

    #[DataProvider('tamperedDetailsFor')]
    public function testATamperedDetailsForOnSellIsTreatedAsEmptyAndItsRawValueNeverReachesAStatement(string $tampered): void
    {
        $this->signIn();
        $tampered = '@branch' === $tampered ? $this->id($this->guitars) : $tampered;
        $token = $this->client->request('GET', '/sell')->filter('input[name="listing_form[_token]"]')->attr('value');
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('POST', '/sell', ['listing_form' => $this->fields($this->id($this->electric), $tampered, []) + ['_token' => $token], 'action' => 'publish']);
        } finally {
            $statements = QueryRecordingStatement::uninstallWithValues($pdo);
        }

        $row = $this->onlyListing();
        self::assertResponseRedirects('/sell/drafts/'.$row['id'].'/edit', 303);
        self::assertSame('draft', $row['status'], 'Treated as empty: not published.');
        self::assertNotSame([], $statements, 'Control: the recorder saw the request.');
        if ($tampered !== $this->id($this->guitars)) {
            foreach ($statements as $statement) {
                foreach ($statement['params'] as [$bound]) {
                    self::assertNotSame($tampered, $bound, 'The raw details_for reached a statement: '.$statement['sql']);
                }
            }
        }
    }

    #[DataProvider('tamperedDetailsFor')]
    public function testATamperedDetailsForOnTheEditPageIsTreatedAsTheStoredCategoryAndItsRawValueNeverReachesAStatement(string $tampered): void
    {
        $draft = $this->annsElectricDraft();
        $tampered = '@branch' === $tampered ? $this->id($this->guitars) : $tampered;
        $details = $this->electricRequired() + [$this->id($this->strings) => '9'];
        $path = '/sell/drafts/'.$draft->id()->toString().'/edit';
        $token = $this->client->request('GET', $path)->filter('input[name="listing_form[_token]"]')->attr('value');
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('POST', $path, ['listing_form' => $this->fields($this->id($this->electric), $tampered, $details) + ['_token' => $token], 'action' => 'save']);
        } finally {
            $statements = QueryRecordingStatement::uninstallWithValues($pdo);
        }

        self::assertResponseRedirects('/sell/drafts', 303);
        self::assertContains($this->id($this->strings), $this->storedAttributes($draft->id()->toString()), 'The form was built for the stored category: its fields bound.');
        self::assertNotSame([], $statements, 'Control: the recorder saw the request.');
        if ($tampered !== $this->id($this->guitars)) {
            foreach ($statements as $statement) {
                foreach ($statement['params'] as [$bound]) {
                    self::assertNotSame($tampered, $bound, 'The raw details_for reached a statement: '.$statement['sql']);
                }
            }
        }
    }

    public function testASellRefusalWithATamperedDetailsForRendersTheHiddenFieldEmptyAndOffersNoPublishButton(): void
    {
        $this->signIn();

        $page = $this->postSell($this->fields($this->id($this->electric), 'tampered-value', [], ['title' => 'x']), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('', (string) $page->filter('#listing_form_details_for')->attr('value'), 'An empty value renders without the attribute.');
        self::assertStringNotContainsString('tampered-value', (string) $page->html());
        self::assertCount(0, $page->filter('button[value="publish"]'), 'No Details on the page: no Save and publish.');
        self::assertSame([], $this->listings());
    }

    public function testAnEditRefusalWithATamperedDetailsForRendersTheHiddenFieldAsTheStoredCategory(): void
    {
        $draft = $this->annsElectricDraft();

        $page = $this->postEdit($draft, $this->fields($this->id($this->electric), 'tampered-value', $this->electricRequired(), ['title' => 'x']), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame($this->id($this->electric), $page->filter('#listing_form_details_for')->attr('value'));
        self::assertStringNotContainsString('tampered-value', (string) $page->html());
    }

    // ---------------------------------------------------------------- details_for mismatch on the edit page (found by /verify)

    private const string ANOTHER_CATEGORY = 'The details shown are for another category. Reload the page.';

    public function testAnEditPostWhoseDetailsWereForAnotherCategoryThanTheSubmittedAndStoredOneIs422AndClearsNothing(): void
    {
        $draft = $this->annsElectricDraft();
        $listings = $this->listings();
        $values = $this->valueRows();

        // A morph to Bass that failed, then a switch back to Electric: Bass's fields, Electric's category.
        $page = $this->postEdit($draft, $this->fields($this->id($this->electric), $this->id($this->bass), $this->bassRequired()), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::ANOTHER_CATEGORY, $page->filter('form')->first()->text());
        $this->assertNothingWritten($listings, $values);
        self::assertCount(3, $this->storedAttributes($draft->id()->toString()), 'Brand, condition and serial survive.');
        self::assertSame([], $this->listingEvents());
    }

    public function testAnEditPostRefilingToAThirdCategoryWithDetailsForAnotherIs422AndChangesNothing(): void
    {
        $draft = $this->annsElectricDraft();
        $listings = $this->listings();
        $values = $this->valueRows();

        // Stored Electric (A), details built for Bass (B), submitted category a third leaf (C).
        $other = CategoryFactory::new(['name' => CategoryName::fromString('Acoustic'), 'slug' => CategorySlug::fromString('acoustic')])->childOf($this->guitars)->create();
        $page = $this->postEdit($draft, $this->fields($this->id($other), $this->id($this->bass), $this->bassRequired()), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::ANOTHER_CATEGORY, $page->filter('form')->first()->text());
        $this->assertNothingWritten($listings, $values);
        self::assertSame([], $this->listingEvents());
    }

    public function testAnArrayValuedDetailsForIsNotA500(): void
    {
        $this->signIn();
        $token = $this->client->request('GET', '/sell')->filter('input[name="listing_form[_token]"]')->attr('value');

        $this->client->request('POST', '/sell', ['listing_form' => $this->fields($this->id($this->electric), '', []) + ['_token' => $token, 'details_for' => ['x']], 'action' => 'publish']);

        $row = $this->onlyListing();
        self::assertResponseRedirects('/sell/drafts/'.$row['id'].'/edit', 303);
        self::assertSame('draft', $row['status'], 'Treated as empty: not published.');
        self::assertSame([], $this->storedAttributes($row['id']));
    }

    // ---------------------------------------------------------------- AC-18

    public function testSellRefusesATitleThatIsTooShortOnTheTitleFieldAndWritesNothing(): void
    {
        $this->signIn();

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired(), ['title' => 'ab']), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Give the listing a title of 3 to 120 characters, on one line.'], $this->errorsOn($page, 'title'));
        self::assertSame([], $this->listings());
    }

    public function testSellRefusesANonNumericDetailAndAnOutOfRangeOneInTheContractsWords(): void
    {
        $this->signIn();
        $field = $this->detailField($this->strings);

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), [$this->id($this->strings) => 'six']), 'save');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Enter a whole number, like 6.'], $this->errorsOn($page, $field));

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), [$this->id($this->strings) => '13']), 'save');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Enter a whole number from 4 to 12.'], $this->errorsOn($page, $field));
        self::assertSame([], $this->listings());
    }

    public function testSellRefusesAnOptionThatIsNotOfferedOnTheDetailsField(): void
    {
        $this->signIn();
        $details = [$this->id($this->condition) => '0198a000-0000-7000-8000-000000000001'];

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $details), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Choose one of the listed options.'], $this->errorsOn($page, $this->detailField($this->condition)));
        self::assertSame([], $this->listings());
    }

    public function testSellRefusesACategoryThatHasSinceGainedSubcategories(): void
    {
        $this->signIn();

        $page = $this->postSell($this->fields($this->id($this->guitars), '', []), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['That category now has subcategories. Choose a more specific one.'], $this->errorsOn($page, 'category'));
        self::assertSame([], $this->listings());
    }

    public function testTheEditPageNamesAnArchivedOptionWhenItIsSubmittedBack(): void
    {
        $draft = $this->annsElectricDraft();
        $handler = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $handler);
        $handler(new ArchiveAttributeOption($this->id($this->brand), $this->optionIdOf($this->brand, 'Fender')));
        $listings = $this->listings();
        $values = $this->valueRows();

        $page = $this->postEdit($draft, $this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired()), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Fender is no longer offered. Choose another.'], $this->errorsOn($page, $this->detailField($this->brand)));
        $this->assertNothingWritten($listings, $values);
    }

    public function testAnAttributeArchivedBetweenTheMorphAndThePostIsRefusedAsAnExtraFieldAndWritesNothing(): void
    {
        $this->signIn();
        // The seller's page offered Strings; an admin archives it before the POST.
        $handler = self::getContainer()->get(ArchiveAttributeHandler::class);
        self::assertInstanceOf(ArchiveAttributeHandler::class, $handler);
        $handler(new ArchiveAttribute($this->id($this->strings)));

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired() + [$this->id($this->strings) => '6']), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This form should not contain extra fields.', $page->filter('form')->first()->text());
        self::assertSame([], $this->listings());
        self::assertSame([], $this->valueRows());
    }

    public function testAnOptionArchivedAfterTheRenderIsRefusedOnItsFieldAndWritesNothing(): void
    {
        $this->signIn();
        $handler = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $handler);
        $handler(new ArchiveAttributeOption($this->id($this->brand), $this->optionIdOf($this->brand, 'Fender')));

        $page = $this->postSell($this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired()), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Choose one of the listed options.'], $this->errorsOn($page, $this->detailField($this->brand)));
        self::assertSame([], $this->listings());
        self::assertSame([], $this->valueRows());
    }

    // ---------------------------------------------------------------- AC-21

    public function testTheLiveComponentPostsItsStateSoNothingSensitiveRidesInAUrl(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->create();

        foreach (['/sell', '/sell/drafts/'.$draft->id()->toString().'/edit'] as $path) {
            $root = $this->client->request('GET', $path)->filter('[data-live-name-value="ListingForm"]');
            self::assertCount(1, $root, $path);
            $method = $root->attr('data-live-request-method-value');
            self::assertContains($method, [null, 'post'], $path.': a morph is a POST; "get" would put the CSRF token and text in the URL.');
            self::assertStringNotContainsString('?', (string) $root->attr('data-live-url-value'));
            self::assertNull($root->attr('data-live-query-mapping-value'));
        }
    }

    public function testTheLiveEndpointRefusesAGetSoStateCanNeverBeSentInAQueryString(): void
    {
        $this->signIn();
        $json = $this->client->request('GET', '/sell')->filter('[data-live-name-value="ListingForm"]')->attr('data-live-props-value');
        self::assertNotNull($json);

        $this->client->request('GET', '/_components/ListingForm', ['props' => $json]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testARealPostWithABadCsrfTokenIs422WithTheRootErrorAndWritesNothing(): void
    {
        $this->signIn();

        $page = $this->client->request('POST', '/sell', ['listing_form' => $this->fields($this->id($this->electric), $this->id($this->electric), $this->electricRequired()) + ['_token' => 'not-a-token'], 'action' => 'publish']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('CSRF', $page->filter('form')->first()->filter('li')->first()->text());
        self::assertSame([], $this->listings());
        self::assertSame([], $this->valueRows());
    }

    public function testARealPostOnTheEditPageWithABadCsrfTokenIsRefusedAndChangesNothing(): void
    {
        $draft = $this->annsElectricDraft();
        $listings = $this->listings();
        $values = $this->valueRows();

        $this->client->request('POST', '/sell/drafts/'.$draft->id()->toString().'/edit', ['listing_form' => $this->fields($this->id($this->bass), $this->id($this->bass), $this->bassRequired(), ['title' => 'Hijacked title']) + ['_token' => 'not-a-token'], 'action' => 'publish']);

        self::assertResponseStatusCodeSame(422);
        $this->assertNothingWritten($listings, $values);
    }
}
