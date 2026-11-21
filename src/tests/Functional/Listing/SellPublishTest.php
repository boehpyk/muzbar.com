<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsListingPages;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * listing-publish AC-16 … AC-20, AC-26 and AC-27 over HTTP against the real database (DAMA). Ann is
 * the seller, Bob the other user; the fixture tree is the spec's (Instruments > Guitars > Electric,
 * Instruments > Bass) and "complete D" is condition = Used, brand = Fender. One `KernelBrowser` per
 * test with `disableReboot()`, so recorded Sentry events survive the request.
 */
final class SellPublishTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use ReadsListingPages;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';
    private const string FILL = 'Fill this in to publish.';

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
        $this->buildTheValuesTree();
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $email): User
    {
        return $this->registerUserWithKnownCredentials($email, self::PASSWORD, verified: true);
    }

    private function signIn(string $email): User
    {
        $user = $this->user($email);
        $this->signInThroughTheLoginForm($this->client, $email, self::PASSWORD);

        return $user;
    }

    /**
     * @return array<string, string> "complete D": condition = Used, brand = Fender
     */
    private function completeDetails(): array
    {
        return [
            $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
        ];
    }

    private function draftOf(User $seller, string $title = 'Fender Precision V'): Listing
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($this->electric)->titled($title)->create();
    }

    private function publishedOf(User $seller, string $title = 'Fender Precision V'): Listing
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($this->electric)->titled($title)->withValues($this->completeDetails())->published()->create();
    }

    private function withdrawnOf(User $seller, string $title = 'Fender Precision V'): Listing
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($this->electric)->titled($title)->withValues($this->completeDetails())->withdrawn()->create();
    }

    private function editPath(Listing $listing): string
    {
        return '/sell/drafts/'.$listing->id()->toString().'/edit';
    }

    private function withdrawPath(Listing|string $listing): string
    {
        return '/sell/drafts/'.($listing instanceof Listing ? $listing->id()->toString() : $listing).'/withdraw';
    }

    /**
     * @return array<string, mixed> the listing row
     */
    private function row(Listing $listing): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM listing_listing WHERE id = ?', [$listing->id()->toString()]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function tables(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id'),
            $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id'),
        ];
    }

    /**
     * @param array<string, string> $details
     * @param array<string, string> $overrides
     *
     * @return array<string, string|array<string, string>>
     */
    private function fields(array $details, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Fender Precision V',
            'description' => 'Mint condition',
            'price' => '1234.50',
            'currency' => 'EUR',
            'category' => $this->electric->id()->toString(),
            'attributes' => $details,
        ];
    }

    /**
     * Opens the edit page for its token, then posts the form with a top-level `action`.
     *
     * @param array<string, string|array<string, string>> $fields
     */
    private function submit(Listing $listing, array $fields, ?string $action): Crawler
    {
        $page = $this->client->request('GET', $this->editPath($listing));
        $token = $page->filter('input[name="listing_form[_token]"]')->attr('value');
        self::assertNotNull($token);

        $parameters = ['listing_form' => $fields + ['_token' => $token]];
        if (null !== $action) {
            $parameters['action'] = $action;
        }

        return $this->client->request('POST', $this->editPath($listing), $parameters);
    }

    /**
     * @return list<string>
     */
    private function errorsOn(Crawler $page, string $field): array
    {
        return $page->filter('#listing_form_'.$field)->ancestors()->first()->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function detailField(Attribute $attribute): string
    {
        return 'attributes_'.$attribute->id()->toString();
    }

    private function valueOf(Crawler $page, string $field): string
    {
        $node = $page->filter('#listing_form_'.$field);
        if ('select' === $node->nodeName()) {
            return $node->filter('option[selected]')->count() > 0 ? (string) $node->filter('option[selected]')->attr('value') : '';
        }

        return 'textarea' === $node->nodeName() ? trim($node->text()) : (string) $node->attr('value');
    }

    private function assertNothingReportedToSentry(): void
    {
        self::assertSame([], $this->sentryEvents(), 'A user error must not be logged at error (AC-27).');
    }

    // ---------------------------------------------------------------- AC-16

    public function testYourListingsShowsOnlyMineNewestFirstWithAStatusWordAndAPublicLinkForPublishedOnes(): void
    {
        $ann = $this->signIn('ann@example.com');
        $bob = $this->user('bob@example.com');
        $draft = $this->draftOf($ann, 'First, a draft');
        $published = $this->publishedOf($ann, 'Second, published');
        $withdrawn = $this->withdrawnOf($ann, 'Third, withdrawn');
        $this->publishedOf($bob, 'Bobs listing');

        $page = $this->client->request('GET', '/sell/drafts');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Your listings', trim($page->filter('h1')->text()));
        self::assertSame('Your listings', trim($page->filter('title')->text()));
        $rows = $page->filter('li.draft');
        self::assertCount(3, $rows);
        self::assertSame(
            [['Third, withdrawn', 'Withdrawn'], ['Second, published', 'Published'], ['First, a draft', 'Draft']],
            $rows->each(static fn (Crawler $li): array => [trim($li->filter('.draft-title')->text()), trim($li->filter('.draft-status')->text())]),
        );
        self::assertSame('/sell/drafts/'.$withdrawn->id()->toString().'/edit', $rows->eq(0)->selectLink('Edit')->attr('href'));
        self::assertSame(
            [0, 1, 0],
            $rows->each(static fn (Crawler $li): int => $li->filter('a.draft-view')->count()),
            'Only the published listing links to its public page.',
        );
        self::assertSame('/listings/'.$published->id()->toString(), $rows->eq(1)->filter('a.draft-view')->attr('href'));
        self::assertSame('/sell/drafts/'.$draft->id()->toString().'/edit', $rows->eq(2)->selectLink('Edit')->attr('href'));
        self::assertStringNotContainsString('Bobs listing', (string) $this->client->getResponse()->getContent());
    }

    // ---------------------------------------------------------------- AC-17

    /**
     * @return list<array{string, string}> label and posted `action` of each button of the listing form
     */
    private function buttons(Crawler $page): array
    {
        return $page->filter('form[name="listing_form"] button')->each(static fn (Crawler $b): array => [trim($b->text()), (string) $b->attr('value')]);
    }

    public function testTheEditPageOfADraftOffersSaveDraftAndSaveAndPublishAndNoWithdraw(): void
    {
        $ann = $this->signIn('ann@example.com');

        $page = $this->client->request('GET', $this->editPath($this->draftOf($ann)));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Edit listing', trim($page->filter('h1')->text()));
        self::assertSame('Status: Draft', trim($page->filter('p.listing-status')->text()));
        self::assertSame([['Save draft', 'save'], ['Save and publish', 'publish']], $this->buttons($page));
        self::assertCount(0, $page->filter('form.listing-withdraw'));
        self::assertCount(0, $page->filter('a.listing-public-link'));
        self::assertNull($page->filter('#listing_form_category')->attr('disabled'));
    }

    public function testTheEditPageOfAPublishedListingLinksToItDisablesTheCategoryAndHasItsOwnWithdrawForm(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);

        $page = $this->client->request('GET', $this->editPath($published));

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('Status: Published', trim((string) preg_replace('/\s+/', ' ', $page->filter('p.listing-status')->text())));
        self::assertSame('/listings/'.$published->id()->toString(), $page->filter('a.listing-public-link')->attr('href'));
        self::assertNotNull($page->filter('#listing_form_category')->attr('disabled'));
        self::assertStringContainsString(
            'Withdraw the listing to move it to another category.',
            $page->filter('#listing_form_category')->ancestors()->first()->text(),
        );
        self::assertSame([['Save changes', 'save']], $this->buttons($page));

        $withdraw = $page->filter('form.listing-withdraw');
        self::assertCount(1, $withdraw);
        self::assertSame('post', strtolower((string) $withdraw->attr('method')));
        self::assertSame($this->withdrawPath($published), $withdraw->attr('action'));
        self::assertNotSame('', (string) $withdraw->filter('input[name="_token"]')->attr('value'));
        self::assertSame('Withdraw', trim($withdraw->filter('button')->text()));
    }

    public function testTheEditPageOfAWithdrawnListingOffersSaveAndSaveAndPublishAgainWithTheCategoryEnabled(): void
    {
        $ann = $this->signIn('ann@example.com');

        $page = $this->client->request('GET', $this->editPath($this->withdrawnOf($ann)));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Status: Withdrawn', trim($page->filter('p.listing-status')->text()));
        self::assertSame([['Save', 'save'], ['Save and publish again', 'publish']], $this->buttons($page));
        self::assertCount(0, $page->filter('form.listing-withdraw'));
        self::assertNull($page->filter('#listing_form_category')->attr('disabled'));
    }

    // ---------------------------------------------------------------- AC-18

    public function testSaveAndPublishOnACompleteDraftRedirects303ToThePublicPageWithTheFlash(): void
    {
        $ann = $this->signIn('ann@example.com');
        $draft = $this->draftOf($ann);

        $this->submit($draft, $this->fields($this->completeDetails(), ['title' => 'Now public']), 'publish');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/listings/'.$draft->id()->toString());
        $row = $this->row($draft);
        self::assertSame('published', $row['status']);
        self::assertSame('Now public', $row['title']);
        self::assertNotNull($row['published_at']);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.flash-success', 'Published. This is what buyers see.');
        $this->assertNothingReportedToSentry();
    }

    public function testSaveAndPublishAgainOnAWithdrawnListingPublishesIt(): void
    {
        $ann = $this->signIn('ann@example.com');
        $withdrawn = $this->withdrawnOf($ann);

        $this->submit($withdrawn, $this->fields($this->completeDetails()), 'publish');

        self::assertResponseRedirects('/listings/'.$withdrawn->id()->toString());
        self::assertSame('published', $this->row($withdrawn)['status']);
    }

    public function testSaveAndPublishOnAnIncompleteDraftIs422MarksEveryMissingFieldKeepsEveryValueAndWritesNothing(): void
    {
        $ann = $this->signIn('ann@example.com');
        $draft = $this->draftOf($ann);
        $before = $this->tables();
        $details = [$this->strings->id()->toString() => '6', $this->serial->id()->toString() => 'SN-7'];

        $page = $this->submit($draft, $this->fields($details, ['title' => 'Typed title', 'description' => 'Typed text', 'price' => '77']), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->condition)));
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->brand)));
        self::assertSame([], $this->errorsOn($page, $this->detailField($this->strings)));
        self::assertSame('Typed title', $this->valueOf($page, 'title'));
        self::assertSame('Typed text', $this->valueOf($page, 'description'));
        self::assertSame('77', $this->valueOf($page, 'price'));
        self::assertSame('EUR', $this->valueOf($page, 'currency'));
        self::assertSame($this->electric->id()->toString(), $this->valueOf($page, 'category'));
        self::assertSame('6', $this->valueOf($page, $this->detailField($this->strings)));
        self::assertSame('SN-7', $this->valueOf($page, $this->detailField($this->serial)));
        self::assertCount(0, $page->filter('.flash'), 'A refused publish renders no flash.');
        self::assertSame($before, $this->tables(), 'The title edit must not be saved either.');
        $this->assertNothingReportedToSentry();
    }

    public function testOnlyTheMissingFieldIsMarkedWhenOneRequiredDetailIsFilled(): void
    {
        $ann = $this->signIn('ann@example.com');

        $page = $this->submit($this->draftOf($ann), $this->fields([$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used')]), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->errorsOn($page, $this->detailField($this->condition)));
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->brand)));
    }

    public function testSaveAndPublishWithAChangedCategorySavesTheRefileDoesNotPublishAndSaysSo(): void
    {
        $ann = $this->signIn('ann@example.com');
        $draft = $this->draftOf($ann);

        $this->submit($draft, $this->fields($this->completeDetails(), ['category' => $this->bass->id()->toString()]), 'publish');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->editPath($draft));
        $row = $this->row($draft);
        self::assertSame('draft', $row['status']);
        self::assertNull($row['published_at']);
        self::assertSame($this->bass->id()->toString(), $row['category_id']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved, not published yet. The details now follow “Bass”: check them, then publish.');
        $this->assertNothingReportedToSentry();
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function notPublishingActions(): iterable
    {
        yield 'save' => ['save'];
        yield 'no action at all' => [null];
        yield 'an unknown action' => ['publish-and-delete'];
    }

    #[DataProvider('notPublishingActions')]
    public function testAnythingButPublishIsAPlainSaveOfTheDraft(?string $action): void
    {
        $ann = $this->signIn('ann@example.com');
        $draft = $this->draftOf($ann);

        $this->submit($draft, $this->fields($this->completeDetails(), ['title' => 'Saved only']), $action);

        self::assertResponseRedirects('/sell/drafts');
        $row = $this->row($draft);
        self::assertSame('draft', $row['status']);
        self::assertSame('Saved only', $row['title']);
        self::assertNull($row['published_at']);
    }

    // ---------------------------------------------------------------- AC-19

    public function testSaveChangesOnAPublishedListingRedirectsToYourListingsStaysPublishedAndSaysSo(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);
        $publishedAt = $this->row($published)['published_at'];

        $this->submit($published, $this->fields($this->completeDetails(), ['title' => 'Typo fixed']), 'save');

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/sell/drafts');
        $row = $this->row($published);
        self::assertSame('published', $row['status']);
        self::assertSame('Typo fixed', $row['title']);
        self::assertSame($publishedAt, $row['published_at']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved. Your listing is updated.');
        $this->assertNothingReportedToSentry();
    }

    public function testClearingARequiredDetailOnAPublishedListingIs422OnThatFieldAndWritesNothing(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);
        $before = $this->tables();
        $details = [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'), $this->brand->id()->toString() => ''];

        $page = $this->submit($published, $this->fields($details, ['title' => 'Never saved']), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([self::FILL], $this->errorsOn($page, $this->detailField($this->brand)));
        self::assertSame([], $this->errorsOn($page, $this->detailField($this->condition)));
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testATamperedCategoryOnAPublishedListingIsIgnoredBecauseTheFieldIsDisabled(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);

        $this->submit($published, $this->fields($this->completeDetails(), ['category' => $this->bass->id()->toString(), 'title' => 'Still on Electric']), 'save');

        self::assertResponseRedirects('/sell/drafts');
        $row = $this->row($published);
        self::assertSame($this->electric->id()->toString(), $row['category_id']);
        self::assertSame('Still on Electric', $row['title']);
        self::assertSame('published', $row['status']);
    }

    public function testAPublishActionOnAPublishedListingIsJustASaveAndKeepsPublishedAt(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);
        $publishedAt = $this->row($published)['published_at'];

        $this->submit($published, $this->fields($this->completeDetails()), 'publish');

        self::assertResponseRedirects('/sell/drafts');
        self::assertSame($publishedAt, $this->row($published)['published_at']);
    }

    public function testANumberOutOfRangeOnAPublishedListingIsAUserErrorNotLoggedAndNothingIsWritten(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);
        $before = $this->tables();

        $page = $this->submit($published, $this->fields($this->completeDetails() + [$this->strings->id()->toString() => '13']), 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Enter a whole number from 4 to 12.'], $this->errorsOn($page, $this->detailField($this->strings)));
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testPublishingFromALegacyDraftOnABranchIs422OnTheCategoryNotLoggedAndNothingIsWritten(): void
    {
        $ann = $this->signIn('ann@example.com');
        // ListingFactory bypasses L-2: a draft on Guitars (a branch) is legacy data the app cannot make.
        $legacy = ListingFactory::new()->soldBy($ann)->inCategory($this->guitars)->create();
        $before = $this->tables();

        $page = $this->submit($legacy, $this->fields($this->completeDetails(), ['category' => $this->guitars->id()->toString()]), 'publish');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['That category now has subcategories. Choose a more specific one.'], $this->errorsOn($page, 'category'));
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-20

    public function testWithdrawingWithAValidTokenRedirects303FlashesAndTakesThePageDown(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);
        $page = $this->client->request('GET', $this->editPath($published));
        $token = (string) $page->filter('form.listing-withdraw input[name="_token"]')->attr('value');
        $values = $this->tables()[1];

        $this->client->request('POST', $this->withdrawPath($published), ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/sell/drafts');
        self::assertSame('withdrawn', $this->row($published)['status']);
        self::assertSame($values, $this->tables()[1]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Withdrawn. It is no longer public.');
        $this->client->request('GET', '/listings/'.$published->id()->toString());
        self::assertResponseStatusCodeSame(404);
        $this->assertNothingReportedToSentry();
    }

    public function testABadOrMissingTokenIs403ForEveryIdOwnedOrNotAndChangesNothing(): void
    {
        $ann = $this->signIn('ann@example.com');
        $bob = $this->user('bob@example.com');
        $mine = $this->publishedOf($ann);
        $theirs = $this->publishedOf($bob);
        $before = $this->tables();

        foreach ([
            'my listing' => $mine->id()->toString(),
            'someone else\'s' => $theirs->id()->toString(),
            'an unknown id' => Uuid::v7()->toRfc4122(),
            'a malformed id' => 'not-a-uuid',
        ] as $label => $id) {
            foreach (['a wrong token' => ['_token' => 'not-the-token'], 'no token' => []] as $how => $parameters) {
                $this->client->request('POST', $this->withdrawPath($id), $parameters);

                self::assertResponseStatusCodeSame(403, $label.' with '.$how);
            }
        }

        self::assertSame($before, $this->tables());
    }

    public function testATokenMintedForAnotherListingIsRefused(): void
    {
        $ann = $this->signIn('ann@example.com');
        $first = $this->publishedOf($ann, 'First');
        $second = $this->publishedOf($ann, 'Second');
        $token = (string) $this->client->request('GET', $this->editPath($first))->filter('form.listing-withdraw input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->withdrawPath($second), ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('published', $this->row($second)['status']);
    }

    public function testWithdrawingADraftIsAnErrorFlashNotAnErrorLogAndTheRowIsUnchanged(): void
    {
        $ann = $this->signIn('ann@example.com');
        $draft = $this->draftOf($ann);
        $this->client->request('GET', '/sell/drafts');
        $token = $this->mintToken($this->client, 'withdraw-listing-'.$draft->id()->toString());
        $before = $this->tables();

        $this->client->request('POST', $this->withdrawPath($draft), ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/sell/drafts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Only a published listing can be withdrawn.');
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testWithdrawingTwiceIsTheSameSuccess(): void
    {
        $ann = $this->signIn('ann@example.com');
        $withdrawn = $this->withdrawnOf($ann);
        $this->client->request('GET', '/sell/drafts');
        $token = $this->mintToken($this->client, 'withdraw-listing-'.$withdrawn->id()->toString());

        $this->client->request('POST', $this->withdrawPath($withdrawn), ['_token' => $token]);

        self::assertResponseRedirects('/sell/drafts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Withdrawn. It is no longer public.');
        self::assertSame('withdrawn', $this->row($withdrawn)['status']);
    }

    public function testAGetOnTheWithdrawUrlIs405AndChangesNothing(): void
    {
        $ann = $this->signIn('ann@example.com');
        $published = $this->publishedOf($ann);

        $this->client->request('GET', $this->withdrawPath($published));

        self::assertResponseStatusCodeSame(405);
        self::assertSame('published', $this->row($published)['status']);
    }

    // ---------------------------------------------------------------- AC-26

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyStatus(): iterable
    {
        yield 'draft' => ['draft'];
        yield 'published' => ['published'];
        yield 'withdrawn' => ['withdrawn'];
    }

    #[DataProvider('everyStatus')]
    public function testBobGetsTheSame404AsForAnUnknownIdOnEveryRouteForAnnsListingAndNothingChanges(string $status): void
    {
        $ann = $this->user('ann@example.com');
        $listing = match ($status) {
            'draft' => $this->draftOf($ann),
            'published' => $this->publishedOf($ann),
            default => $this->withdrawnOf($ann),
        };
        $this->signIn('bob@example.com');
        $unknown = Uuid::v7()->toRfc4122();
        $this->client->request('GET', '/sell/drafts');
        $tokens = [
            $listing->id()->toString() => $this->mintToken($this->client, 'withdraw-listing-'.$listing->id()->toString()),
            $unknown => $this->mintToken($this->client, 'withdraw-listing-'.$unknown),
        ];
        $editToken = (string) $this->client->request('GET', '/sell')->filter('input[name="listing_form[_token]"]')->attr('value');
        $before = $this->tables();
        // The debug page prints this test's own call, tokens included: they are not part of what the page says.
        $ids = [$listing->id()->toString(), $unknown, ...array_values($tokens), $editToken];

        $bodies = [];
        foreach ([$listing->id()->toString() => 'Ann\'s', $unknown => 'unknown'] as $id => $which) {
            $this->client->request('GET', '/sell/drafts/'.$id.'/edit');
            self::assertResponseStatusCodeSame(404, "GET edit, $which, $status");
            $bodies['GET edit'][] = $this->normalised((string) $this->client->getResponse()->getContent(), $ids);

            $this->client->request('POST', '/sell/drafts/'.$id.'/edit', ['listing_form' => $this->fields($this->completeDetails()) + ['_token' => $editToken], 'action' => 'publish']);
            self::assertResponseStatusCodeSame(404, "POST edit, $which, $status");
            $bodies['POST edit'][] = $this->normalised((string) $this->client->getResponse()->getContent(), $ids);

            $this->client->request('POST', $this->withdrawPath($id), ['_token' => $tokens[$id]]);
            self::assertResponseStatusCodeSame(404, "POST withdraw, $which, $status");
            $bodies['POST withdraw'][] = $this->normalised((string) $this->client->getResponse()->getContent(), $ids);
        }

        foreach ($bodies as $route => $pair) {
            self::assertCount(1, array_unique($pair), $route.': the 404 bodies differ, so one of them leaks that the listing exists.');
        }
        self::assertSame($before, $this->tables(), 'Bob changed Ann\'s listing.');
        $this->assertNothingReportedToSentry();
    }
}
