<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Fixture\RacingCategoryRepository;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * AC-22 … AC-30 and AC-33 over HTTP, against the real database (DAMA). One `KernelBrowser` per test
 * (the session is the sign-in), `disableReboot()` so container overrides and the recorded Sentry
 * events survive the request. Rows are read back through DBAL, never an identity map.
 */
final class CategoryAdminTest extends WebTestCase
{
    use ClearsRateLimiters;
    use ReadsSentryEvents;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string PASSWORD = 'a-correct-password-1';
    private const string TOO_DEEP = 'Categories can be at most 4 levels deep.';

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

    private function signInAsAdmin(): void
    {
        $this->registerUserWithKnownCredentials('admin@example.com', self::PASSWORD, verified: true, admin: true);
        $this->signInThroughTheLoginForm($this->client, 'admin@example.com', self::PASSWORD);
    }

    private function signInAsPlainUser(): void
    {
        $this->registerUserWithKnownCredentials('plain@example.com', self::PASSWORD, verified: true);
        $this->signInThroughTheLoginForm($this->client, 'plain@example.com', self::PASSWORD);
    }

    private function category(string $slug, ?Category $parent = null, ?string $name = null, ?string $id = null): Category
    {
        $attributes = [
            'name' => CategoryName::fromString($name ?? ucfirst($slug)),
            'slug' => CategorySlug::fromString($slug),
        ];
        if (null !== $id) {
            $attributes['id'] = CategoryId::fromString($id);
        }
        $factory = CategoryFactory::new($attributes);

        return (null === $parent ? $factory : $factory->childOf($parent))->create();
    }

    /**
     * @return array{guitars: Category, electric: Category, solidBody: Category}
     */
    private function guitarsTree(): array
    {
        $guitars = $this->category('guitars');
        $electric = $this->category('electric', $guitars);

        return ['guitars' => $guitars, 'electric' => $electric, 'solidBody' => $this->category('solid-body', $electric)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function table(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, parent_id, slug, name FROM catalog_category ORDER BY id');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowOf(Category $category): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, parent_id, slug, name FROM catalog_category WHERE id = ?', [$category->id()->toString()]);

        return false === $row ? null : $row;
    }

    private function token(string $path, string $name = 'category_form'): string
    {
        $token = $this->client->request('GET', $path)->filter(\sprintf('input[name="%s[_token]"]', $name))->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    /**
     * GET the form for its CSRF token, then POST `$fields` (or the given token) the way a browser would.
     *
     * @param array<string, string> $fields
     */
    private function submit(string $path, array $fields, ?string $token = null): Crawler
    {
        $fields['_token'] = $token ?? $this->token($path);

        return $this->client->request('POST', $path, ['category_form' => $fields]);
    }

    /**
     * @return list<string>
     */
    private function errorsOn(Crawler $page, string $field): array
    {
        $row = $page->filter('#category_form_'.$field)->ancestors()->first();

        return $row->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function valueOf(Crawler $page, string $field): string
    {
        $node = $page->filter('#category_form_'.$field);
        if ('select' === $node->nodeName()) {
            return $node->filter('option[selected]')->count() > 0 ? (string) $node->filter('option[selected]')->attr('value') : '';
        }

        return (string) $node->attr('value');
    }

    private function assertNothingReportedToSentry(): void
    {
        self::assertSame([], $this->sentryEvents(), 'A domain refusal must not be logged at error (AC-33).');
    }

    /**
     * @param array<string, string> $fields
     */
    private function assertRefusedOnField(string $path, array $fields, string $field, string $message): void
    {
        $before = $this->table();

        $page = $this->submit($path, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->errorsOn($page, $field));
        foreach (array_diff(['name', 'slug', 'parent'], [$field]) as $other) {
            self::assertSame([], $this->errorsOn($page, $other), "Unexpected error on $other.");
        }
        foreach (['name', 'slug', 'parent'] as $name) {
            self::assertSame($fields[$name] ?? '', $this->valueOf($page, $name), "$name did not keep its submitted value.");
        }
        self::assertSame($before, $this->table(), 'Nothing may be persisted.');
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-22 authz

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'index' => ['GET', '/admin/categories'];
        yield 'new (GET)' => ['GET', '/admin/categories/new'];
        yield 'new (POST)' => ['POST', '/admin/categories/new'];
        yield 'edit (GET)' => ['GET', '/admin/categories/{id}/edit'];
        yield 'edit (POST)' => ['POST', '/admin/categories/{id}/edit'];
        yield 'delete (POST)' => ['POST', '/admin/categories/{id}/delete'];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostileBody(string $method, string $path, Category $target): array
    {
        if ('GET' === $method) {
            return [];
        }

        // The delete token is a VALID one for this very session. A bad token would be answered 403
        // (or, for an anonymous caller, a redirect to /login) by the controller's own CSRF check,
        // which looks exactly like the access rule doing its job: with the rule removed the matrix
        // would stay green on the delete route.
        return str_ends_with($path, '/delete')
            ? ['_token' => $this->mintDeleteToken($target)]
            : ['category_form' => ['name' => 'Injected', 'slug' => 'injected', 'parent' => '', '_token' => 'x']];
    }

    /**
     * Puts a genuine `delete-category-<id>` token into the browser's session (creating the session
     * and its cookie if the browser has none yet) and returns it. This is how a caller who is not
     * allowed to see the admin page, hence cannot scrape one, still holds a valid token.
     */
    private function mintDeleteToken(Category $category): string
    {
        $container = self::getContainer();
        $factory = $container->get('session.factory');
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Session\SessionFactoryInterface::class, $factory);
        $stack = $container->get('request_stack');
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\RequestStack::class, $stack);
        $tokens = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class, $tokens);

        $session = $factory->createSession();
        $cookie = $this->client->getCookieJar()->get($session->getName());
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }
        $session->start();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $stack->push($request);
        try {
            $value = $tokens->getToken('delete-category-'.$category->id()->toString())->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();
        if (null === $cookie) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));
        }

        return $value;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnAnonymousVisitorIsSentToLoginOnEveryAdminRouteAndNothingChanges(string $method, string $path): void
    {
        $guitars = $this->category('guitars');
        $before = $this->table();

        $this->client->request($method, str_replace('{id}', $guitars->id()->toString(), $path), $this->hostileBody($method, $path, $guitars));

        self::assertResponseRedirects('/login');
        self::assertSame($before, $this->table());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAUserWithoutTheAdminRoleGets403OnEveryAdminRouteAndNothingChanges(string $method, string $path): void
    {
        $guitars = $this->category('guitars');
        $this->signInAsPlainUser();
        $before = $this->table();

        $this->client->request($method, str_replace('{id}', $guitars->id()->toString(), $path), $this->hostileBody($method, $path, $guitars));

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->table());
    }

    public function testAnAdminGets200OnTheGetRoutes(): void
    {
        $guitars = $this->category('guitars');
        $this->signInAsAdmin();

        foreach (['/admin/categories', '/admin/categories/new', '/admin/categories/'.$guitars->id()->toString().'/edit'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
        }
    }

    public function testAnAdminGets303AfterEachSuccessfulPost(): void
    {
        $guitars = $this->category('guitars');
        $leaf = $this->category('drums');
        $this->signInAsAdmin();

        $this->submit('/admin/categories/new', ['name' => 'Basses', 'slug' => 'basses', 'parent' => '']);
        self::assertResponseStatusCodeSame(303);

        $this->submit('/admin/categories/'.$guitars->id()->toString().'/edit', ['name' => 'Guitars!', 'slug' => 'guitars', 'parent' => '']);
        self::assertResponseStatusCodeSame(303);

        $token = $this->client->request('GET', '/admin/categories')->filter(\sprintf('form[action$="%s/delete"] input[name=_token]', $leaf->id()->toString()))->attr('value');
        $this->client->request('POST', '/admin/categories/'.$leaf->id()->toString().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
    }

    public function testTheAccessRuleIsAnchoredSoAdministratorIsNotCaughtByIt(): void
    {
        $this->client->request('GET', '/administrator');

        self::assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------- AC-23 index

    public function testTheIndexListsEveryCategoryDepthFirstOrderedByNameThenIdWithIndentation(): void
    {
        $same1 = '018f0000-0000-7000-8000-000000000001';
        $same2 = '018f0000-0000-7000-8000-000000000002';
        $zebra = $this->category('zebra', name: 'zebra');
        $alpha = $this->category('alpha', name: 'Alpha');
        $this->category('alpha-two', $alpha, 'Alpha child');
        $this->category('same-b', name: 'Same', id: $same2);
        $this->category('same-a', name: 'Same', id: $same1);
        $this->category('beta', name: 'beta');
        unset($zebra);
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories');

        $rows = $page->filter('tbody tr')->each(static fn (Crawler $tr): array => [
            preg_replace('/\s+/', ' ', trim($tr->filter('td')->eq(0)->text())),
            trim($tr->filter('td')->eq(1)->text()),
        ]);
        self::assertSame([
            ['Alpha', 'alpha'],
            ['— Alpha child', 'alpha-two'],
            ['beta', 'beta'],
            ['Same', 'same-a'],
            ['Same', 'same-b'],
            ['zebra', 'zebra'],
        ], $rows);
    }

    public function testEachRowHasEditAndDeleteAndAddChildIsAbsentOnlyAtDepthFour(): void
    {
        $a = $this->category('level-1');
        $b = $this->category('level-2', $a);
        $c = $this->category('level-3', $b);
        $this->category('level-4', $c);
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories');

        $rows = $page->filter('tbody tr');
        self::assertCount(4, $rows);
        foreach ($rows as $i => $tr) {
            $row = new Crawler($tr);
            self::assertSame(1, $row->filter('a:contains("Edit")')->count(), "row $i Edit");
            self::assertSame(1, $row->filter('button:contains("Delete")')->count(), "row $i Delete");
            self::assertSame($i < 3 ? 1 : 0, $row->filter('a:contains("Add child")')->count(), "row $i Add child");
        }
        self::assertSame(1, $page->filter('a:contains("Add root category")')->count());
    }

    public function testTheIndexIndentsEachLevelByOneDash(): void
    {
        $a = $this->category('level-1');
        $b = $this->category('level-2', $a);
        $c = $this->category('level-3', $b);
        $this->category('level-4', $c);
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories');

        $names = $page->filter('tbody tr td:first-child')->each(static fn (Crawler $td): string => preg_replace('/\s+/', ' ', trim($td->text())) ?? '');
        self::assertSame(['Level-1', '— Level-2', '— — Level-3', '— — — Level-4'], $names);
    }

    public function testTheIndexReadsTheTreeWithASingleQuery(): void
    {
        $this->guitarsTree();
        $this->signInAsAdmin();
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', '/admin/categories');
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        $touchingTheTree = array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_category'));
        self::assertCount(1, $touchingTheTree, "Queries on catalog_category:\n".implode("\n", $touchingTheTree));
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the count above proves nothing.');
    }

    // ---------------------------------------------------------------- AC-24 create

    public function testCreatingThroughTheFormRedirects303WithAFlashNamingTheCategory(): void
    {
        $guitars = $this->category('guitars');
        $this->signInAsAdmin();

        $this->submit('/admin/categories/new', ['name' => '  Electric  ', 'slug' => 'electric', 'parent' => $guitars->id()->toString()]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/categories');
        $row = $this->db->fetchAssociative("SELECT parent_id, name FROM catalog_category WHERE slug = 'electric'");
        self::assertSame(['parent_id' => $guitars->id()->toString(), 'name' => 'Electric'], $row);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Created “Electric”.');
    }

    public function testTheParentQueryParameterPreselectsThatParent(): void
    {
        $guitars = $this->category('guitars');
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories/new?parent='.$guitars->id()->toString());

        self::assertSame($guitars->id()->toString(), $this->valueOf($page, 'parent'));
    }

    public function testAnUnknownParentQueryParameterIsIgnored(): void
    {
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories/new?parent='.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('', $this->valueOf($page, 'parent'));
    }

    public function testTheParentSelectListsEveryCategoryIndentedWithARootOption(): void
    {
        $this->guitarsTree();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories/new');

        $labels = $page->filter('#category_form_parent option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertSame(['— none (root) —', 'Guitars', '— Electric', '— — Solid-body'], $labels);
    }

    // ---------------------------------------------------------------- AC-25 slug derivation

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function derivations(): iterable
    {
        yield 'ampersand' => ['Bass & Guitars', 'bass-guitars'];
        yield 'cyrillic' => ['Электрогитары', 'elektrogitary'];
        yield 'cut at 64 without a trailing hyphen' => [str_repeat('a', 63).' b', str_repeat('a', 63)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('derivations')]
    public function testABlankSlugIsDerivedFromTheName(string $name, string $expected): void
    {
        $this->signInAsAdmin();

        $this->submit('/admin/categories/new', ['name' => $name, 'slug' => '', 'parent' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([$expected], $this->db->fetchFirstColumn('SELECT slug FROM catalog_category'));
    }

    public function testAnUnderivableSlugIsReportedOnTheSlugFieldAndNothingIsPersisted(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            '/admin/categories/new',
            ['name' => '!!!', 'slug' => '', 'parent' => ''],
            'slug',
            'Enter a slug: one could not be derived from this name.',
        );
    }

    // ---------------------------------------------------------------- AC-26 failure contract

    public function testAnEmptyNameIsRefusedOnTheNameField(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => '', 'slug' => 'x', 'parent' => ''], 'name', 'Name must be 1–100 characters, without line breaks.');
    }

    public function testATooLongNameIsRefusedOnTheNameField(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => str_repeat('a', 101), 'slug' => 'x', 'parent' => ''], 'name', 'Name must be 1–100 characters, without line breaks.');
    }

    public function testANameWithALineBreakIsRefusedOnTheNameField(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => "Bass\nGuitars", 'slug' => 'x', 'parent' => ''], 'name', 'Name must be 1–100 characters, without line breaks.');
    }

    public function testAMalformedSlugIsRefusedOnTheSlugField(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => 'Bass', 'slug' => 'bass_guitars', 'parent' => ''], 'slug', 'Use lower-case letters, digits and single hyphens (max 64).');
    }

    public function testATooLongSlugIsRefusedOnTheSlugField(): void
    {
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => 'Bass', 'slug' => str_repeat('a', 65), 'parent' => ''], 'slug', 'Use lower-case letters, digits and single hyphens (max 64).');
    }

    public function testATakenSlugIsRefusedOnTheSlugField(): void
    {
        $this->category('guitars');
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => 'Other', 'slug' => 'guitars', 'parent' => ''], 'slug', '“guitars” is already used by another category at this level.');
    }

    public function testACategoryBelowTheDepthCeilingIsRefusedOnTheParentField(): void
    {
        $a = $this->category('level-1');
        $b = $this->category('level-2', $a);
        $c = $this->category('level-3', $b);
        $d = $this->category('level-4', $c);
        $this->signInAsAdmin();

        $this->assertRefusedOnField('/admin/categories/new', ['name' => 'Five', 'slug' => 'five', 'parent' => $d->id()->toString()], 'parent', self::TOO_DEEP);
    }

    public function testMovingIntoADescendantIsRefusedOnTheParentField(): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            '/admin/categories/'.$tree['guitars']->id()->toString().'/edit',
            ['name' => 'Guitars', 'slug' => 'guitars', 'parent' => $tree['solidBody']->id()->toString()],
            'parent',
            'A category cannot be moved inside itself.',
        );
    }

    public function testMovingASubtreeBeyondTheCeilingIsRefusedOnTheParentField(): void
    {
        $tree = $this->guitarsTree();
        $x = $this->category('x');
        $y = $this->category('y', $x);
        $z = $this->category('z', $y);
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            '/admin/categories/'.$tree['electric']->id()->toString().'/edit',
            ['name' => 'Electric', 'slug' => 'electric', 'parent' => $z->id()->toString()],
            'parent',
            self::TOO_DEEP,
        );
    }

    public function testATamperedParentIsRejectedByTheFormAndNothingIsPersisted(): void
    {
        $this->signInAsAdmin();
        $before = $this->table();

        $this->submit('/admin/categories/new', ['name' => 'X', 'slug' => 'x', 'parent' => Uuid::v7()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->table());
        $this->assertNothingReportedToSentry();
    }

    public function testAParentDeletedBetweenValidationAndTheHandlerIsReportedOnTheParentField(): void
    {
        $guitars = $this->category('guitars');
        $this->signInAsAdmin();
        $this->raceBefore('lineageOf', fn () => $this->db->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$guitars->id()->toString()]));

        $page = $this->submit('/admin/categories/new', ['name' => 'Electric', 'slug' => 'electric', 'parent' => $guitars->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['That parent no longer exists.'], $this->errorsOn($page, 'parent'));
        // The interloper's delete ran inside the handler's savepoint, which the refusal rolled back;
        // what matters is that the refused create wrote nothing.
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM catalog_category WHERE slug = 'electric'"));
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- C-3 race (plan Risk 2)

    private function raceBefore(string $method, \Closure $interloper): void
    {
        // The concrete id, set before the first request builds a handler; the adapter is built
        // here rather than fetched, because an initialised service can no longer be replaced.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::getContainer()->set(
            DoctrineCategoryRepository::class,
            new RacingCategoryRepository(new DoctrineCategoryRepository($em), $interloper, $method),
        );
    }

    public function testASlugTakenBetweenThePreCheckAndTheSaveRendersThe422NotAClosedEntityManager(): void
    {
        $this->signInAsAdmin();
        $this->raceBefore('save', fn () => $this->db->executeStatement(
            'INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, NULL, ?, ?)',
            [Uuid::v7()->toRfc4122(), 'guitars', 'The other admin\'s Guitars'],
        ));

        $page = $this->submit('/admin/categories/new', ['name' => 'Guitars', 'slug' => 'guitars', 'parent' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('EntityManager is closed', (string) $this->client->getResponse()->getContent());
        self::assertSame(['“guitars” is already used by another category at this level.'], $this->errorsOn($page, 'slug'));
        self::assertSame('Guitars', $this->valueOf($page, 'name'));
        // The interloper's insert shared the handler's savepoint, so the refusal rolled it back too;
        // what matters is that our own `Guitars` was not written.
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM catalog_category WHERE name = 'Guitars'"));
        $this->assertNothingReportedToSentry();
    }

    public function testTheSameRaceOnAnEditRendersThe422WithTheEditedCategoryStillPresent(): void
    {
        $guitars = $this->category('guitars');
        $electric = $this->category('electric', $guitars);
        $this->signInAsAdmin();
        $this->raceBefore('save', fn () => $this->db->executeStatement(
            'INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $guitars->id()->toString(), 'acoustic', 'Acoustic'],
        ));

        $page = $this->submit('/admin/categories/'.$electric->id()->toString().'/edit', ['name' => 'Electric', 'slug' => 'acoustic', 'parent' => $guitars->id()->toString()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['“acoustic” is already used by another category at this level.'], $this->errorsOn($page, 'slug'));
        self::assertSame('electric', $this->rowOf($electric)['slug'] ?? null);
    }

    // ---------------------------------------------------------------- AC-27 edit

    public function testTheEditFormIsPrefilledAndExcludesTheEditedCategoryFromItsParents(): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories/'.$tree['electric']->id()->toString().'/edit');

        self::assertSame('Electric', $this->valueOf($page, 'name'));
        self::assertSame('electric', $this->valueOf($page, 'slug'));
        self::assertSame($tree['guitars']->id()->toString(), $this->valueOf($page, 'parent'));
        $options = $page->filter('#category_form_parent option')->each(static fn (Crawler $o): string => (string) $o->attr('value'));
        self::assertNotContains($tree['electric']->id()->toString(), $options);
        self::assertContains($tree['solidBody']->id()->toString(), $options, 'Descendants stay listed: the domain refuses the move (AC-27).');
    }

    public function testOneSubmitCanRenameReSlugAndMove(): void
    {
        $tree = $this->guitarsTree();
        $basses = $this->category('basses');
        $this->signInAsAdmin();

        $this->submit('/admin/categories/'.$tree['electric']->id()->toString().'/edit', ['name' => 'Fretted', 'slug' => 'fretted', 'parent' => $basses->id()->toString()]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            ['id' => $tree['electric']->id()->toString(), 'parent_id' => $basses->id()->toString(), 'slug' => 'fretted', 'name' => 'Fretted'],
            $this->rowOf($tree['electric']),
        );
    }

    // ---------------------------------------------------------------- AC-28 delete

    private function deleteToken(Category $category): string
    {
        $token = $this->client->request('GET', '/admin/categories')
            ->filter(\sprintf('form[action$="/%s/delete"] input[name=_token]', $category->id()->toString()))
            ->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    public function testDeletingALeafRemovesItAndRedirects303WithAFlash(): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/categories/'.$tree['solidBody']->id()->toString().'/delete', ['_token' => $this->deleteToken($tree['solidBody'])]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/categories');
        self::assertNull($this->rowOf($tree['solidBody']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Deleted “Solid-body”.');
    }

    public function testDeletingANonLeafRedirects303WithTheErrorFlashAndDeletesNothing(): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();
        $before = $this->table();

        $this->client->request('POST', '/admin/categories/'.$tree['electric']->id()->toString().'/delete', ['_token' => $this->deleteToken($tree['electric'])]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/categories');
        self::assertSame($before, $this->table());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', '“Electric” has subcategories. Move or delete them first.');
        $this->assertNothingReportedToSentry();
    }

    public function testThereIsNoGetRouteThatDeletes(): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();
        $before = $this->table();

        $this->client->request('GET', '/admin/categories/'.$tree['solidBody']->id()->toString().'/delete');

        self::assertResponseStatusCodeSame(405);
        self::assertSame($before, $this->table());
    }

    // ---------------------------------------------------------------- AC-29 CSRF

    /**
     * @return iterable<string, array{?string}>
     */
    public static function badTokens(): iterable
    {
        yield 'wrong token' => ['not-the-token'];
        yield 'missing token' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function testCreateWithABadCsrfTokenMutatesNothingAndRenders422(string $token): void
    {
        $this->signInAsAdmin();
        $before = $this->table();

        $this->submit('/admin/categories/new', ['name' => 'Guitars', 'slug' => 'guitars', 'parent' => ''], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->table());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function testEditWithABadCsrfTokenMutatesNothingAndRenders422(string $token): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();
        $before = $this->table();

        $this->submit('/admin/categories/'.$tree['electric']->id()->toString().'/edit', ['name' => 'Hacked', 'slug' => 'hacked', 'parent' => ''], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->table());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function testDeleteWithABadCsrfTokenAnswers403AndDeletesNothing(string $token): void
    {
        $tree = $this->guitarsTree();
        $this->signInAsAdmin();
        $before = $this->table();

        $this->client->request('POST', '/admin/categories/'.$tree['solidBody']->id()->toString().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->table());
    }

    // ---------------------------------------------------------------- AC-30 unknown ids

    public function testEditingAnUnknownCategoryIs404(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/categories/'.Uuid::v7()->toRfc4122().'/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPostingAnEditForAnUnknownCategoryIs404AndWritesNothing(): void
    {
        $this->signInAsAdmin();
        $this->client->request('POST', '/admin/categories/'.Uuid::v7()->toRfc4122().'/edit', ['category_form' => ['name' => 'X', 'slug' => 'x', 'parent' => '']]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->table());
    }

    public function testDeletingAnUnknownCategoryWithAValidTokenIs404(): void
    {
        $doomed = $this->category('doomed');
        $this->signInAsAdmin();
        $token = $this->deleteToken($doomed);
        $this->db->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$doomed->id()->toString()]);

        $this->client->request('POST', '/admin/categories/'.$doomed->id()->toString().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedIdRoutes(): iterable
    {
        yield 'edit GET' => ['GET', '/admin/categories/not-a-uuid/edit'];
        yield 'edit POST' => ['POST', '/admin/categories/not-a-uuid/edit'];
        yield 'delete POST' => ['POST', '/admin/categories/not-a-uuid/delete'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIdRoutes')]
    public function testAMalformedIdIs404(string $method, string $path): void
    {
        $this->guitarsTree();
        $this->signInAsAdmin();
        $before = $this->table();

        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->table());
    }

    // ---------------------------------------------------------------- AC-33 corruption is reported

    public function testCorruptStoredDataIsA500ReportedToSentryAndWritesNothing(): void
    {
        // A root with a chain six levels deep below it: deeper than the tree can legally be.
        $root = Uuid::v7()->toRfc4122();
        $this->db->executeStatement('INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, NULL, ?, ?)', [$root, 'root', 'Root']);
        $parent = $root;
        for ($i = 1; $i <= 5; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $this->db->executeStatement('INSERT INTO catalog_category (id, parent_id, slug, name) VALUES (?, ?, ?, ?)', [$id, $parent, 'level-'.$i, 'Level '.$i]);
            $parent = $id;
        }
        $other = $this->category('other');
        $this->signInAsAdmin();
        $before = $this->table();

        // Moving `root` makes the handler measure its subtree height, which walks into the corruption.
        $this->client->request('GET', '/admin/categories/'.$root.'/edit');
        $token = $this->client->getCrawler()->filter('input[name="category_form[_token]"]')->attr('value');
        $this->client->request('POST', '/admin/categories/'.$root.'/edit', ['category_form' => ['name' => 'Root', 'slug' => 'root', 'parent' => $other->id()->toString(), '_token' => $token]]);

        self::assertResponseStatusCodeSame(500);
        self::assertSame($before, $this->table());
        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        // Sentry has no `critical` level: Monolog's CRITICAL maps to `fatal`.
        self::assertSame('fatal', (string) $events[0]->getLevel());
    }
}
