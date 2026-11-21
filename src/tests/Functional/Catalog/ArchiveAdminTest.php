<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\AttributeOptionFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\MintsCsrfTokens;
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
 * listing-schema-drift AC-16, AC-17, AC-19 (the message is gone) and AC-22 (the admin half) over
 * HTTP, against the real database (DAMA). One `KernelBrowser` per test, `disableReboot()`. Expected
 * wording comes from the feature spec, not from the templates. Fixture: Guitars defines `brand`
 * (Fender, Gibson); Electric is its child.
 */
final class ArchiveAdminTest extends WebTestCase
{
    use ClearsRateLimiters;
    use MintsCsrfTokens;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;
    private Connection $db;
    private Category $guitars;
    private Category $electric;
    private Attribute $brand;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars'), 'slug' => CategorySlug::fromString('guitars')])->create();
        $this->electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric'), 'slug' => CategorySlug::fromString('electric')])->childOf($this->guitars)->create();
        $this->brand = AttributeFactory::new()->inCategory($this->guitars)->coded('brand')->labelled('Brand')->choice(['Fender', 'Gibson'])->create();
    }

    private function signInAsAdmin(): void
    {
        $this->registerUserWithKnownCredentials('admin@example.com', self::PASSWORD, verified: true, admin: true);
        $this->signInThroughTheLoginForm($this->client, 'admin@example.com', self::PASSWORD);
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function tables(): array
    {
        return [
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute ORDER BY id'),
            $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option ORDER BY id'),
        ];
    }

    private function optionId(Attribute $attribute, string $label): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$attribute->id()->toString(), $label]);
        self::assertIsString($id);

        return $id;
    }

    private function listPath(Category $category): string
    {
        return \sprintf('/admin/categories/%s/attributes', $category->id()->toString());
    }

    private function editPath(Attribute $attribute): string
    {
        return \sprintf('/admin/attributes/%s/edit', $attribute->id()->toString());
    }

    private function token(string $id): string
    {
        return $this->mintCsrfToken($this->client, $id);
    }

    private function optionsToken(Attribute $attribute): string
    {
        return $this->token('attribute-options-'.$attribute->id()->toString());
    }

    private function archivedAtOf(string $table, string $id): mixed
    {
        return $this->db->fetchOne('SELECT archived_at FROM '.$table.' WHERE id = ?', [$id]);
    }

    private function assertNothingReportedToSentry(): void
    {
        self::assertSame([], $this->sentryEvents(), 'A user-error refusal must not be logged at error (AC-22).');
    }

    // ================================================================== AC-17: archive / restore an attribute

    public function testArchivingAnAttributeAnswers303ToTheListWithTheSpecifiedFlash(): void
    {
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/archive', ['_token' => $this->token('archive-attribute-'.$this->brand->id()->toString())]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->listPath($this->guitars));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Archived “Brand”. Sellers no longer see it; listings keep their values.');
        self::assertNotNull($this->archivedAtOf('catalog_attribute', $this->brand->id()->toString()));
        $this->assertNothingReportedToSentry();
    }

    public function testRestoringAnAttributeAnswers303ToTheListWithTheSpecifiedFlash(): void
    {
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->text()->archived()->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$archived->id()->toString().'/restore', ['_token' => $this->token('restore-attribute-'.$archived->id()->toString())]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->listPath($this->guitars));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Restored “Finish”.');
        self::assertNull($this->archivedAtOf('catalog_attribute', $archived->id()->toString()));
    }

    public function testArchivingTwiceStillSucceedsAndKeepsTheFirstInstant(): void
    {
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->text()->archived()->create();
        $first = $this->archivedAtOf('catalog_attribute', $archived->id()->toString());
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$archived->id()->toString().'/archive', ['_token' => $this->token('archive-attribute-'.$archived->id()->toString())]);

        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-success');
        self::assertSame($first, $this->archivedAtOf('catalog_attribute', $archived->id()->toString()));
    }

    // ================================================================== AC-17: archive / restore an option

    public function testArchivingAnOptionAnswers303ToTheEditPageWithTheSpecifiedFlash(): void
    {
        $this->signInAsAdmin();
        $fender = $this->optionId($this->brand, 'Fender');

        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$fender.'/archive', ['_token' => $this->optionsToken($this->brand)]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->editPath($this->brand));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Archived “Fender”.');
        self::assertNotNull($this->archivedAtOf('catalog_attribute_option', $fender));
        $this->assertNothingReportedToSentry();
    }

    public function testRestoringAnOptionAnswers303ToTheEditPageWithTheSpecifiedFlash(): void
    {
        $attribute = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->choice(['Matte'])->create();
        $gloss = AttributeOptionFactory::new()->forAttribute($attribute)->labelled('Gloss')->archived()->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options/'.$gloss->id()->toString().'/restore', ['_token' => $this->optionsToken($attribute)]);

        self::assertResponseRedirects($this->editPath($attribute));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Restored “Gloss”.');
        self::assertNull($this->archivedAtOf('catalog_attribute_option', $gloss->id()->toString()));
    }

    public function testArchivingTheLastActiveOptionIsAnErrorFlashAndChangesNothing(): void
    {
        $this->signInAsAdmin();
        $fender = $this->optionId($this->brand, 'Fender');
        $gibson = $this->optionId($this->brand, 'Gibson');
        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$fender.'/archive', ['_token' => $this->optionsToken($this->brand)]);
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$gibson.'/archive', ['_token' => $this->optionsToken($this->brand)]);

        self::assertResponseRedirects($this->editPath($this->brand));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'A choice needs at least one option sellers can pick.');
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testRemovingTheLastActiveOptionKeepsItsOldWording(): void
    {
        $this->signInAsAdmin();
        $fender = $this->optionId($this->brand, 'Fender');
        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$fender.'/archive', ['_token' => $this->optionsToken($this->brand)]);

        $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$this->optionId($this->brand, 'Gibson').'/delete', ['_token' => $this->optionsToken($this->brand)]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'A choice attribute needs at least one option. Delete the attribute instead.');
    }

    // ================================================================== AC-17: tampering and wrong verbs

    /**
     * @return iterable<string, array{string, string}> a label and a path template; {a} attribute, {o} an option
     */
    public static function archiveRoutes(): iterable
    {
        yield 'archive attribute' => ['archive-attribute-{a}', '/admin/attributes/{a}/archive'];
        yield 'restore attribute' => ['restore-attribute-{a}', '/admin/attributes/{a}/restore'];
        yield 'archive option' => ['attribute-options-{a}', '/admin/attributes/{a}/options/{o}/archive'];
        yield 'restore option' => ['attribute-options-{a}', '/admin/attributes/{a}/options/{o}/restore'];
    }

    private function resolved(string $template, ?string $attributeId = null, ?string $optionId = null): string
    {
        return str_replace(['{a}', '{o}'], [$attributeId ?? $this->brand->id()->toString(), $optionId ?? $this->optionId($this->brand, 'Fender')], $template);
    }

    #[DataProvider('archiveRoutes')]
    public function testABadOrMissingTokenIs403AndChangesNothing(string $tokenTemplate, string $pathTemplate): void
    {
        $this->signInAsAdmin();
        $path = $this->resolved($pathTemplate);
        $other = AttributeFactory::new()->inCategory($this->guitars)->coded('other')->text()->create();
        $before = $this->tables();

        $this->client->request('POST', $path, ['_token' => 'not-a-token']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $path);
        self::assertResponseStatusCodeSame(403);

        // A valid token minted for ANOTHER attribute is not this one's.
        $this->client->request('POST', $path, ['_token' => $this->token($this->resolved($tokenTemplate, $other->id()->toString()))]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($before, $this->tables());
    }

    #[DataProvider('archiveRoutes')]
    public function testAnAnonymousVisitorIsSentToLoginAndNothingChanges(string $tokenTemplate, string $pathTemplate): void
    {
        $before = $this->tables();

        $this->client->request('POST', $this->resolved($pathTemplate), ['_token' => $this->token($this->resolved($tokenTemplate))]);

        self::assertResponseRedirects('/login');
        self::assertSame($before, $this->tables());
    }

    #[DataProvider('archiveRoutes')]
    public function testAUserWithoutTheAdminRoleGets403EvenWithAValidTokenAndNothingChanges(string $tokenTemplate, string $pathTemplate): void
    {
        $this->registerUserWithKnownCredentials('plain@example.com', self::PASSWORD, verified: true);
        $this->signInThroughTheLoginForm($this->client, 'plain@example.com', self::PASSWORD);
        $before = $this->tables();

        $this->client->request('POST', $this->resolved($pathTemplate), ['_token' => $this->token($this->resolved($tokenTemplate))]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->tables());
    }

    #[DataProvider('archiveRoutes')]
    public function testTheTokenIsCheckedBeforeAnyLookupSoAnUnknownIdWithABadTokenIs403Not404(string $tokenTemplate, string $pathTemplate): void
    {
        $this->signInAsAdmin();
        $unknown = Uuid::v7()->toRfc4122();

        $this->client->request('POST', $this->resolved($pathTemplate, $unknown, Uuid::v7()->toRfc4122()), ['_token' => 'not-a-token']);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('archiveRoutes')]
    public function testAGetIs405(string $tokenTemplate, string $pathTemplate): void
    {
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('GET', $this->resolved($pathTemplate));

        self::assertResponseStatusCodeSame(405);
        self::assertSame($before, $this->tables());
    }

    #[DataProvider('archiveRoutes')]
    public function testAnUnknownAttributeWithAValidTokenIs404(string $tokenTemplate, string $pathTemplate): void
    {
        $this->signInAsAdmin();
        $unknown = Uuid::v7()->toRfc4122();

        $this->client->request('POST', $this->resolved($pathTemplate, $unknown), ['_token' => $this->token($this->resolved($tokenTemplate, $unknown))]);

        self::assertResponseStatusCodeSame(404);
        $this->assertNothingReportedToSentry();
    }

    public function testAnUnknownOrForeignOptionWithAValidTokenIs404OnBothOptionRoutes(): void
    {
        $this->signInAsAdmin();
        $foreign = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->choice(['Matte', 'Gloss'])->create();
        $foreignOption = $this->optionId($foreign, 'Matte');
        $before = $this->tables();

        foreach (['archive', 'restore'] as $action) {
            foreach ([Uuid::v7()->toRfc4122(), $foreignOption] as $optionId) {
                $this->client->request('POST', '/admin/attributes/'.$this->brand->id()->toString().'/options/'.$optionId.'/'.$action, ['_token' => $this->optionsToken($this->brand)]);
                self::assertResponseStatusCodeSame(404, $action.' '.$optionId);
            }
        }

        self::assertSame($before, $this->tables());
    }

    // ================================================================== AC-16: the lists

    public function testAfterArchivingBrandTheDefiningCategoryListsItUnderArchivedNotDefinedHere(): void
    {
        $finish = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->text()->create();
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('serial')->labelled('Serial')->text()->archived()->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($this->guitars));

        self::assertResponseStatusCodeSame(200);
        $own = $page->filter('table')->first()->filter('tbody tr')->each(static fn (Crawler $tr): string => trim($tr->filter('td')->eq(1)->text()));
        self::assertEqualsCanonicalizing(['brand', 'finish'], $own);
        self::assertCount(1, $page->filter('h2:contains("Archived")'));
        $rows = $page->filter('table.archived-attributes tbody tr');
        self::assertCount(1, $rows);
        self::assertSame('serial', trim($rows->first()->filter('td')->eq(1)->text()));
        self::assertStringContainsString('Archived on 21 Nov 2026', $rows->first()->text());
        $restore = $rows->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/restore"]', $archived->id()->toString()));
        $delete = $rows->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/delete"]', $archived->id()->toString()));
        self::assertCount(1, $restore);
        self::assertCount(1, $restore->filter('input[name="_token"]'));
        self::assertCount(1, $delete);
        self::assertCount(1, $delete->filter('input[name="_token"]'));
        self::assertCount(0, $page->filter('table.archived-attributes form[action$="/archive"]'), 'An archived row must not offer Archive.');
        self::assertCount(1, $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/archive"]', $finish->id()->toString())), 'An active row has Archive.');
        self::assertCount(1, $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/archive"]', $this->brand->id()->toString())));
    }

    public function testTheListOffersNoArchivedSectionWhenNothingIsArchived(): void
    {
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($this->guitars));

        self::assertCount(0, $page->filter('table.archived-attributes'));
        self::assertCount(0, $page->filter('h2:contains("Archived")'));
    }

    public function testADescendantShowsNeitherTheArchivedAttributeAsInheritedNorTheArchivedSection(): void
    {
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('serial')->labelled('Serial')->text()->archived()->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($this->electric));

        self::assertResponseStatusCodeSame(200);
        $codes = $page->filter('tbody tr')->each(static fn (Crawler $tr): string => trim($tr->filter('td')->eq(1)->text()));
        self::assertSame(['brand'], $codes);
        self::assertNotContains('serial', $codes);
        self::assertCount(0, $page->filter('table.archived-attributes'));
        self::assertCount(0, $page->filter(\sprintf('form[action$="/%s/restore"]', $archived->id()->toString())));
    }

    public function testTheListIssuesExactlyTwoAttributeQueriesTheEffectiveSchemaAndTheArchivedRows(): void
    {
        AttributeFactory::new()->inCategory($this->guitars)->coded('serial')->text()->archived()->create();
        $this->signInAsAdmin();
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $this->listPath($this->guitars));
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        $touching = array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_attribute'));
        self::assertCount(2, $touching, "Queries on the attribute tables:\n".implode("\n", $touching));
        self::assertCount(1, array_filter($touching, static fn (string $q): bool => str_contains($q, 'archived_at IS NOT NULL')));
    }

    // ================================================================== AC-17: the edit page

    public function testTheEditPageSplitsActiveFromArchivedOptions(): void
    {
        AttributeOptionFactory::new()->forAttribute($this->brand)->labelled('Ibanez')->archived()->create();
        $this->signInAsAdmin();
        $ibanez = $this->optionId($this->brand, 'Ibanez');

        $page = $this->client->request('GET', $this->editPath($this->brand));

        self::assertResponseStatusCodeSame(200);
        $active = $page->filter('ul.active-options li')->each(static fn (Crawler $li): string => (string) $li->filter('input[name="label"]')->attr('value'));
        self::assertSame(['Fender', 'Gibson'], $active);
        self::assertCount(1, $page->filter('h2:contains("Archived options")'));
        $archived = $page->filter('ul.archived-options li');
        self::assertCount(1, $archived);
        self::assertStringContainsString('Ibanez — archived on 21 Nov 2026', preg_replace('/\s+/', ' ', trim($archived->text())) ?? '');
        self::assertCount(1, $archived->filter(\sprintf('form[action$="/options/%s/restore"] input[name="_token"]', $ibanez)));
        self::assertCount(1, $archived->filter(\sprintf('form[action$="/options/%s/delete"] input[name="_token"]', $ibanez)));
        self::assertCount(0, $archived->filter('form[action$="/archive"]'));
        self::assertCount(2, $page->filter('ul.active-options form[action$="/archive"]'), 'Each active option has Archive.');
    }

    public function testTheEditPageOfAnAttributeWithNoArchivedOptionsHasNoArchivedSectionAndNoBanner(): void
    {
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($this->brand));

        self::assertCount(0, $page->filter('h2:contains("Archived options")'));
        self::assertCount(0, $page->filter('ul.archived-options'));
        self::assertCount(0, $page->filter('.archived-banner'));
    }

    public function testAnArchivedAttributesEditPageShowsTheBannerWithRestore(): void
    {
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->text()->archived()->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($archived));

        self::assertResponseStatusCodeSame(200);
        $banner = $page->filter('.archived-banner');
        self::assertCount(1, $banner);
        self::assertStringContainsString("Archived on 21 Nov 2026 — sellers don't see it.", $banner->text());
        $restore = $banner->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/restore"]', $archived->id()->toString()));
        self::assertCount(1, $restore);
        self::assertCount(1, $restore->filter('input[name="_token"]'));
    }

    // ================================================================== AC-22: a GET writes nothing

    public function testEveryAdminGetOverArchivedDataLeavesEveryTableUnchanged(): void
    {
        $archived = AttributeFactory::new()->inCategory($this->guitars)->coded('finish')->labelled('Finish')->choice(['Matte'])->archived()->create();
        AttributeOptionFactory::new()->forAttribute($this->brand)->labelled('Ibanez')->archived()->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        foreach ([$this->listPath($this->guitars), $this->listPath($this->electric), $this->editPath($this->brand), $this->editPath($archived)] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
        }

        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    // ================================================================== AC-19: the old message is gone

    public function testTheRetiredMoveRefusalMessageExistsNowhereInTheSource(): void
    {
        $root = \dirname(__DIR__, 3);
        $hits = [];

        foreach (['/src', '/templates'] as $dir) {
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.$dir, \FilesystemIterator::SKIP_DOTS));
            $scanned = 0;
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                ++$scanned;
                if (str_contains((string) file_get_contents($file->getPathname()), 'have details filled in')) {
                    $hits[] = $file->getPathname();
                }
            }
            self::assertGreaterThan(0, $scanned, $dir.' scanned nothing, so the absence below proves nothing.');
        }

        self::assertSame([], $hits, 'C-27 was retired: its message must not linger.');
    }
}
