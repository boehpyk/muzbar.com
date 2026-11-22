<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use App\Infrastructure\Catalog\Http\Controller\AttributeAdminController;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeOptionRepository;
use App\Infrastructure\Catalog\Persistence\Doctrine\DoctrineAttributeRepository;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Fixture\RacingAttributeOptionRepository;
use App\Tests\Fixture\RacingAttributeRepository;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsSentryEvents;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * AC-32 … AC-42 over HTTP, against the real database (DAMA). One `KernelBrowser` per test (the
 * session is the sign-in), `disableReboot()` so container overrides and the recorded Sentry events
 * survive the request. Rows are read back through DBAL, never an identity map.
 *
 * The expected wording comes from the feature spec's failure contract, not from the templates.
 */
final class AttributeAdminTest extends WebTestCase
{
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

    /**
     * @return list<string>
     */
    private function optionLabels(Attribute $attribute): array
    {
        /** @var list<string> $labels */
        $labels = $this->db->fetchFirstColumn('SELECT label FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id', [$attribute->id()->toString()]);

        return $labels;
    }

    private function optionId(Attribute $attribute, string $label): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$attribute->id()->toString(), $label]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * Puts a genuine CSRF token for `$tokenId` into the browser's session (creating the session
     * and its cookie if the browser has none yet) and returns it. This is how a caller who may not
     * see the admin pages, hence cannot scrape a token, still holds a valid one — so a 403 or a
     * redirect in the authz matrix can only come from the access rule, never from the CSRF check.
     */
    private function mintToken(string $tokenId): string
    {
        $container = self::getContainer();
        $factory = $container->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $stack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $tokens = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);

        $session = $factory->createSession();
        $cookie = $this->client->getCookieJar()->get($session->getName());
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $stack->push($request);
        try {
            $value = $tokens->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();
        if (null === $cookie) {
            $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        }

        return $value;
    }

    private function formToken(string $path): string
    {
        $token = $this->client->request('GET', $path)->filter('input[name="attribute_form[_token]"]')->attr('value');
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
        $fields['_token'] = $token ?? $this->formToken($path);

        return $this->client->request('POST', $path, ['attribute_form' => $fields]);
    }

    /**
     * @return list<string>
     */
    private function errorsOn(Crawler $page, string $field): array
    {
        $row = $page->filter('#attribute_form_'.$field)->ancestors()->first();

        return $row->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function valueOf(Crawler $page, string $field): string
    {
        $node = $page->filter('#attribute_form_'.$field);
        if ('textarea' === $node->nodeName()) {
            return $node->text(null, false);
        }

        return (string) $node->attr('value');
    }

    private function assertNothingReportedToSentry(): void
    {
        self::assertSame([], $this->sentryEvents(), 'A domain refusal must not be logged at error (AC-42).');
    }

    /**
     * AC-36: the exact message on the stated field and nowhere else, 422, every field keeping its
     * submitted value, nothing persisted, nothing sent to Sentry.
     *
     * @param array<string, string> $fields
     */
    private function assertRefusedOnField(string $path, array $fields, string $field, string $message): void
    {
        $before = $this->tables();

        $page = $this->submit($path, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$message], $this->errorsOn($page, $field));
        foreach (array_diff(array_keys($fields), [$field]) as $other) {
            self::assertSame([], $this->errorsOn($page, $other), "Unexpected error on $other.");
        }
        foreach ($fields as $name => $value) {
            self::assertSame($value, $this->valueOf($page, $name), "$name did not keep its submitted value.");
        }
        self::assertSame($before, $this->tables(), 'Nothing may be persisted.');
        $this->assertNothingReportedToSentry();
    }

    private function newPath(Category $category, string $type): string
    {
        return \sprintf('/admin/categories/%s/attributes/new/%s', $category->id()->toString(), $type);
    }

    private function editPath(Attribute $attribute): string
    {
        return \sprintf('/admin/attributes/%s/edit', $attribute->id()->toString());
    }

    private function listPath(Category $category): string
    {
        return \sprintf('/admin/categories/%s/attributes', $category->id()->toString());
    }

    private function optionsToken(Attribute $attribute): string
    {
        return $this->mintToken('attribute-options-'.$attribute->id()->toString());
    }

    /**
     * @return array{Category, Attribute}
     */
    private function categoryWithChoice(): array
    {
        $category = CategoryFactory::createOne();
        $attribute = AttributeFactory::new()->inCategory($category)->coded('orientation')->labelled('Orientation')->choice(['Left', 'Right'])->create();

        return [$category, $attribute];
    }

    // ---------------------------------------------------------------- AC-32 authz

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'index' => ['GET', '/admin/categories/{cat}/attributes'];
        yield 'new choice (GET)' => ['GET', '/admin/categories/{cat}/attributes/new/choice'];
        yield 'new number (POST)' => ['POST', '/admin/categories/{cat}/attributes/new/number'];
        yield 'new boolean (POST)' => ['POST', '/admin/categories/{cat}/attributes/new/boolean'];
        yield 'new text (POST)' => ['POST', '/admin/categories/{cat}/attributes/new/text'];
        yield 'edit (GET)' => ['GET', '/admin/attributes/{attr}/edit'];
        yield 'edit (POST)' => ['POST', '/admin/attributes/{attr}/edit'];
        yield 'delete (POST)' => ['POST', '/admin/attributes/{attr}/delete'];
        yield 'option add (POST)' => ['POST', '/admin/attributes/{attr}/options'];
        yield 'option relabel (POST)' => ['POST', '/admin/attributes/{attr}/options/{opt}'];
        yield 'option remove (POST)' => ['POST', '/admin/attributes/{attr}/options/{opt}/delete'];
    }

    private function resolve(string $template, Category $category, Attribute $attribute): string
    {
        return str_replace(
            ['{cat}', '{attr}', '{opt}'],
            [$category->id()->toString(), $attribute->id()->toString(), $this->optionId($attribute, 'Left')],
            $template,
        );
    }

    /**
     * A body that WOULD succeed for an admin, with a VALID token where the route checks one. A bad
     * token would be answered 403 (or, for an anonymous caller, a redirect) by the controller's
     * own CSRF check, which looks exactly like the access rule doing its job.
     *
     * @return array<string, mixed>
     */
    private function hostileBody(string $method, string $path, Attribute $attribute): array
    {
        if ('GET' === $method) {
            return [];
        }
        if (str_ends_with($path, '/delete') && str_contains($path, '/options/')) {
            return ['_token' => $this->optionsToken($attribute)];
        }
        if (str_ends_with($path, '/delete')) {
            return ['_token' => $this->mintToken('delete-attribute-'.$attribute->id()->toString())];
        }
        if (str_contains($path, '/options')) {
            return ['_token' => $this->optionsToken($attribute), 'label' => 'Injected'];
        }

        return ['attribute_form' => ['label' => 'Injected', 'code' => 'injected', '_token' => 'x']];
    }

    #[DataProvider('routes')]
    public function testAnAnonymousVisitorIsSentToLoginOnEveryAttributeRouteAndNothingChanges(string $method, string $template): void
    {
        [$category, $attribute] = $this->categoryWithChoice();
        $path = $this->resolve($template, $category, $attribute);
        $before = $this->tables();

        $this->client->request($method, $path, $this->hostileBody($method, $path, $attribute));

        self::assertResponseRedirects('/login');
        self::assertSame($before, $this->tables());
    }

    #[DataProvider('routes')]
    public function testAUserWithoutTheAdminRoleGets403OnEveryAttributeRouteAndNothingChanges(string $method, string $template): void
    {
        [$category, $attribute] = $this->categoryWithChoice();
        $this->signInAsPlainUser();
        $path = $this->resolve($template, $category, $attribute);
        $before = $this->tables();

        $this->client->request($method, $path, $this->hostileBody($method, $path, $attribute));

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->tables());
    }

    public function testAnAdminGets200OnEveryGetRoute(): void
    {
        [$category, $attribute] = $this->categoryWithChoice();
        $this->signInAsAdmin();

        $paths = [$this->listPath($category), $this->editPath($attribute)];
        foreach (['choice', 'number', 'boolean', 'text'] as $type) {
            $paths[] = $this->newPath($category, $type);
        }
        foreach ($paths as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(200, $path);
        }
    }

    public function testAnAdminGets303AfterEachSuccessfulPost(): void
    {
        [$category, $attribute] = $this->categoryWithChoice();
        $doomed = AttributeFactory::new()->inCategory($category)->text()->create();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'text'), ['label' => 'Serial', 'code' => 'serial']);
        self::assertResponseStatusCodeSame(303, 'define');

        $this->submit($this->editPath($attribute), ['label' => 'Handedness', 'code' => 'orientation']);
        self::assertResponseStatusCodeSame(303, 'edit');

        $base = '/admin/attributes/'.$attribute->id()->toString();
        $this->client->request('POST', $base.'/options', ['_token' => $this->optionsToken($attribute), 'label' => 'Centre']);
        self::assertResponseStatusCodeSame(303, 'option add');

        $this->client->request('POST', $base.'/options/'.$this->optionId($attribute, 'Left'), ['_token' => $this->optionsToken($attribute), 'label' => 'Port']);
        self::assertResponseStatusCodeSame(303, 'option relabel');

        $this->client->request('POST', $base.'/options/'.$this->optionId($attribute, 'Centre').'/delete', ['_token' => $this->optionsToken($attribute)]);
        self::assertResponseStatusCodeSame(303, 'option remove');

        $this->client->request('POST', '/admin/attributes/'.$doomed->id()->toString().'/delete', ['_token' => $this->mintToken('delete-attribute-'.$doomed->id()->toString())]);
        self::assertResponseStatusCodeSame(303, 'delete');
    }

    public function testTheControllerCarriesTheAdminRoleAttribute(): void
    {
        $attributes = (new \ReflectionClass(AttributeAdminController::class))->getAttributes(IsGranted::class);

        self::assertCount(1, $attributes);
        self::assertSame('ROLE_ADMIN', $attributes[0]->newInstance()->attribute);
    }

    // ---------------------------------------------------------------- AC-33 list

    public function testTheListShowsTheCategoryAndOneRowPerAttributeOrderedByLabelCaseInsensitively(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->labelled('banana')->coded('banana')->choice(['A', 'B', 'C'])->create();
        AttributeFactory::new()->inCategory($category)->labelled('Cherry')->coded('cherry')->text()->create();
        AttributeFactory::new()->inCategory($category)->labelled('Apple')->coded('apple')->number(NumberRules::of(Unit::fromString('mm'), 5, 10))->create();
        AttributeFactory::new()->labelled('Elsewhere')->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($category));

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', $category->name()->toString());
        $rows = $page->filter('tbody tr')->each(static fn (Crawler $tr): array => array_map(
            static fn (string $text): string => preg_replace('/\s+/', ' ', trim($text)) ?? '',
            $tr->filter('td')->each(static fn (Crawler $td): string => $td->text()),
        ));
        self::assertCount(3, $rows);
        self::assertSame(['Apple', 'apple', 'Number', ''], \array_slice($rows[0], 0, 4));
        self::assertStringContainsString('unit: mm', $rows[0][4]);
        self::assertStringContainsString('min: 5', $rows[0][4]);
        self::assertStringContainsString('max: 10', $rows[0][4]);
        self::assertSame(['banana', 'banana', 'Choice', ''], \array_slice($rows[1], 0, 4));
        self::assertStringContainsString('3 options', $rows[1][4]);
        self::assertSame(['Cherry', 'cherry', 'Text', ''], \array_slice($rows[2], 0, 4));
    }

    public function testARequiredAttributeIsMarkedRequiredInTheList(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->with(['required' => true])->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($category));

        self::assertStringContainsString('required', $page->filter('tbody tr td')->eq(3)->text());
    }

    public function testEachRowHasAnEditLinkAndAPostDeleteFormAndTheCategoryHasOneDefineLinkPerType(): void
    {
        [$category, $attribute] = $this->categoryWithChoice();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($category));

        self::assertCount(1, $page->filter(\sprintf('a[href="%s"]', $this->editPath($attribute))));
        $delete = $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/delete"]', $attribute->id()->toString()));
        self::assertCount(1, $delete);
        self::assertCount(1, $delete->filter('input[name="_token"]'));
        foreach (['choice', 'number', 'boolean', 'text'] as $type) {
            self::assertCount(1, $page->filter(\sprintf('a[href="%s"]', $this->newPath($category, $type))), $type);
        }
    }

    public function testEveryCategoryRowOnTheCategoryIndexLinksToItsAttributes(): void
    {
        $guitars = CategoryFactory::createOne();
        $electric = CategoryFactory::new()->childOf($guitars)->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', '/admin/categories');

        foreach ([$guitars, $electric] as $category) {
            self::assertCount(1, $page->filter(\sprintf('a[href="%s"]', $this->listPath($category))), $category->slug()->toString());
        }
    }

    public function testTheAttributesAndTheirOptionCountsComeFromOneQuery(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->choice(['A', 'B'])->create();
        AttributeFactory::new()->inCategory($category)->choice(['C'])->create();
        AttributeFactory::new()->inCategory($category)->text()->create();
        $this->signInAsAdmin();
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $this->listPath($category));
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        // listing-schema-drift (D9): the "Archived" section is its own query, `archivedDefinedOn()`,
        // and not part of the effective schema this test counts.
        $touching = array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_attribute') && !str_contains($q, 'archived_at IS NOT NULL'));
        self::assertCount(1, $touching, "Queries on the attribute tables:\n".implode("\n", $touching));
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the count above proves nothing.');
    }

    // ---------------------------------------------------------------- AC-34 define via form

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function formShapes(): iterable
    {
        yield 'choice' => ['choice', ['label', 'code', 'required', 'options'], ['unit', 'minimum', 'maximum']];
        yield 'number' => ['number', ['label', 'code', 'required', 'unit', 'minimum', 'maximum'], ['options']];
        yield 'boolean' => ['boolean', ['label', 'code', 'required'], ['unit', 'minimum', 'maximum', 'options']];
        yield 'text' => ['text', ['label', 'code', 'required'], ['unit', 'minimum', 'maximum', 'options']];
    }

    /**
     * @param list<string> $present
     * @param list<string> $absent
     */
    #[DataProvider('formShapes')]
    public function testTheDefineFormShowsExactlyTheFieldsOfItsType(string $type, array $present, array $absent): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->newPath($category, $type));

        self::assertResponseStatusCodeSame(200);
        foreach ($present as $field) {
            self::assertCount(1, $page->filter('#attribute_form_'.$field), "$field should be on the $type form.");
        }
        foreach ($absent as $field) {
            self::assertCount(0, $page->filter('#attribute_form_'.$field), "$field must not be on the $type form.");
        }
    }

    public function testDefiningAChoiceTrimsLinesDropsBlankOnesAndKeepsTheOrder(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'choice'), ['label' => 'Orientation', 'code' => 'orientation', 'options' => "  Left  \r\n\r\n   \nRight\n  Centre "]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->listPath($category));
        $id = $this->db->fetchOne("SELECT id FROM catalog_attribute WHERE code = 'orientation'");
        self::assertIsString($id);
        self::assertSame(
            ['Left', 'Right', 'Centre'],
            $this->db->fetchFirstColumn('SELECT label FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY id', [$id]),
        );
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Defined “Orientation”.');
    }

    public function testDefiningANumberPersistsItsUnitAndBounds(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'number'), ['label' => 'Scale length', 'code' => 'scale', 'unit' => 'mm', 'minimum' => '500', 'maximum' => '700']);

        self::assertResponseStatusCodeSame(303);
        $row = $this->db->fetchAssociative("SELECT type, number_rules FROM catalog_attribute WHERE code = 'scale'");
        self::assertNotFalse($row);
        self::assertSame('number', $row['type']);
        self::assertIsString($row['number_rules']);
        self::assertEquals(['unit' => 'mm', 'min' => 500, 'max' => 700], json_decode($row['number_rules'], true));
    }

    public function testANumberWithNoUnitOrBoundsIsAccepted(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'number'), ['label' => 'Frets', 'code' => 'frets', 'unit' => '', 'minimum' => '', 'maximum' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->db->fetchOne("SELECT count(*) FROM catalog_attribute WHERE code = 'frets'"));
    }

    public function testDefiningABooleanAndATextEachAnswer303(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'boolean'), ['label' => 'Has case', 'code' => 'has-case', 'required' => '1']);
        self::assertResponseStatusCodeSame(303);
        $this->submit($this->newPath($category, 'text'), ['label' => 'Serial', 'code' => 'serial']);
        self::assertResponseStatusCodeSame(303);

        self::assertSame(['boolean' => true, 'text' => false], $this->db->fetchAllKeyValue('SELECT type, is_required FROM catalog_attribute'));
    }

    public function testAnUnknownTypeAnUnknownCategoryAndAMalformedCategoryIdAreAll404(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        foreach ([
            $this->newPath($category, 'enum'),
            \sprintf('/admin/categories/%s/attributes/new/text', Uuid::v7()->toRfc4122()),
            '/admin/categories/not-a-uuid/attributes/new/text',
            \sprintf('/admin/categories/%s/attributes', Uuid::v7()->toRfc4122()),
            '/admin/categories/not-a-uuid/attributes',
        ] as $path) {
            foreach (['GET', 'POST'] as $method) {
                if ('POST' === $method && !str_contains($path, '/new/')) {
                    continue;
                }
                $this->client->request($method, $path, 'POST' === $method ? ['attribute_form' => ['label' => 'X', 'code' => 'x']] : []);
                self::assertResponseStatusCodeSame(404, "$method $path");
            }
        }
        self::assertSame([[], []], $this->tables());
    }

    // ---------------------------------------------------------------- AC-35 code derivation

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function derivations(): iterable
    {
        yield 'ascii' => ['Fretboard wood', 'fretboard-wood'];
        yield 'cyrillic' => ['Гриф', 'grif'];
    }

    #[DataProvider('derivations')]
    public function testABlankCodeIsDerivedFromTheLabel(string $label, string $expected): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'text'), ['label' => $label, 'code' => '']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame($expected, $this->db->fetchOne('SELECT code FROM catalog_attribute'));
    }

    public function testAnUnderivableLabelIsRefusedOnTheCodeField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'text'),
            ['label' => '!!!', 'code' => ''],
            'code',
            'Enter a code: one could not be derived from this label.',
        );
    }

    // ---------------------------------------------------------------- AC-36 errors on the right field

    public function testALabelOverOneHundredCharactersIsRefusedOnTheLabelField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($category, 'text'), ['label' => str_repeat('a', 101), 'code' => 'long'], 'label', 'Label must be 1–100 characters, without line breaks.');
    }

    public function testABlankLabelWithAnExplicitCodeIsRefusedOnTheLabelField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($category, 'text'), ['label' => '', 'code' => 'blank'], 'label', 'Label must be 1–100 characters, without line breaks.');
    }

    public function testAMalformedCodeIsRefusedOnTheCodeField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($category, 'text'), ['label' => 'Serial', 'code' => 'Bad_Code'], 'code', 'Use lower-case letters, digits and single hyphens (max 64).');
    }

    public function testACodeAlreadyUsedInTheCategoryIsRefusedOnTheCodeField(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->coded('string-count')->create();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'text'),
            ['label' => 'Strings', 'code' => 'string-count'],
            'code',
            '“string-count” is already used by an attribute of this category or of a category above or below it.',
        );
    }

    public function testAnInvalidUnitIsRefusedOnTheUnitField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'number'),
            ['label' => 'Scale', 'code' => 'scale', 'unit' => str_repeat('m', 17), 'minimum' => '1', 'maximum' => '2'],
            'unit',
            'Unit must be 1–16 characters, without line breaks.',
        );
    }

    public function testAMinimumAboveTheMaximumIsRefusedOnTheMaximumField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'number'),
            ['label' => 'Scale', 'code' => 'scale', 'unit' => 'mm', 'minimum' => '9', 'maximum' => '1'],
            'maximum',
            'Maximum must not be lower than minimum.',
        );
    }

    #[DataProvider('nonIntegers')]
    public function testANonIntegerBoundIsRefusedOnItsOwnField(string $field): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();
        $fields = ['label' => 'Scale', 'code' => 'scale', 'unit' => 'mm', 'minimum' => '1', 'maximum' => '2'];
        $fields[$field] = 'twelve';

        $this->assertRefusedOnField($this->newPath($category, 'number'), $fields, $field, 'Enter a whole number.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'minimum' => ['minimum'];
        yield 'maximum' => ['maximum'];
    }

    public function testAChoiceWithNoOptionsIsRefusedOnTheOptionsField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'choice'),
            ['label' => 'Orientation', 'code' => 'orientation', 'options' => ''],
            'options',
            'A choice attribute needs at least one option.',
        );
    }

    public function testAnInvalidOptionLineIsRefusedOnTheOptionsField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'choice'),
            ['label' => 'Orientation', 'code' => 'orientation', 'options' => "Left\n".str_repeat('x', 101)],
            'options',
            'Each option must be 1–100 characters.',
        );
    }

    public function testADuplicateOptionLineIsRefusedOnTheOptionsFieldNamingTheLabel(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->assertRefusedOnField(
            $this->newPath($category, 'choice'),
            ['label' => 'Orientation', 'code' => 'orientation', 'options' => "Left\nRight\nLEFT"],
            'options',
            '“LEFT” is listed twice.',
        );
    }

    public function testEditRefusesAnInvalidCodeAndAMinimumAboveTheMaximumOnTheirFields(): void
    {
        $attribute = AttributeFactory::new()->number(NumberRules::of(null, 1, 9))->coded('scale')->create();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->editPath($attribute), ['label' => 'Scale', 'code' => 'Bad_Code', 'unit' => '', 'minimum' => '1', 'maximum' => '9'], 'code', 'Use lower-case letters, digits and single hyphens (max 64).');
        $this->assertRefusedOnField($this->editPath($attribute), ['label' => 'Scale', 'code' => 'scale', 'unit' => '', 'minimum' => '9', 'maximum' => '1'], 'maximum', 'Maximum must not be lower than minimum.');
    }

    public function testEditRefusesACodeUsedByAnotherAttributeOfTheCategory(): void
    {
        $category = CategoryFactory::createOne();
        AttributeFactory::new()->inCategory($category)->coded('string-count')->create();
        $attribute = AttributeFactory::new()->inCategory($category)->coded('frets')->create();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->editPath($attribute), ['label' => 'Frets', 'code' => 'string-count'], 'code', '“string-count” is already used by an attribute of this category or of a category above or below it.');
    }

    // ---------------------------------------------------------------- index races (C-11, C-17)

    /**
     * @param \Closure(?\App\Domain\Catalog\ValueObject\AttributeId): mixed $interloper
     */
    private function raceAttributeSave(\Closure $interloper): RacingAttributeRepository
    {
        // The concrete id, set before the first request builds a handler; the adapter is built
        // here rather than fetched, because an initialised service can no longer be replaced.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $racing = new RacingAttributeRepository(new DoctrineAttributeRepository($em), $interloper);
        self::getContainer()->set(DoctrineAttributeRepository::class, $racing);

        return $racing;
    }

    private function raceOptionSave(\Closure $interloper): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::getContainer()->set(DoctrineAttributeOptionRepository::class, new RacingAttributeOptionRepository(new DoctrineAttributeOptionRepository($em), $interloper));
    }

    public function testACodeTakenBetweenThePreCheckAndTheSaveOnDefineRenders422NotAClosedEntityManager(): void
    {
        $category = CategoryFactory::createOne();
        $categoryId = $category->id()->toString();
        $this->signInAsAdmin();
        $this->raceAttributeSave(fn () => $this->db->executeStatement(
            'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required) VALUES (?, ?, ?, ?, ?, false)',
            [Uuid::v7()->toRfc4122(), $categoryId, 'serial', 'The other admin\'s Serial', 'text'],
        ));
        $before = $this->tables();

        $page = $this->submit($this->newPath($category, 'text'), ['label' => 'Serial', 'code' => 'serial']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('EntityManager is closed', (string) $this->client->getResponse()->getContent());
        self::assertSame(['“serial” is already used by an attribute of this category or of a category above or below it.'], $this->errorsOn($page, 'code'));
        self::assertSame('Serial', $this->valueOf($page, 'label'));
        self::assertSame('serial', $this->valueOf($page, 'code'));
        // The interloper's insert shared the handler's savepoint, so the refusal rolled it back too.
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testACodeTakenBetweenThePreCheckAndTheSaveOnEditRenders422WithTheAttributeStillPresent(): void
    {
        $category = CategoryFactory::createOne();
        $categoryId = $category->id()->toString();
        $attribute = AttributeFactory::new()->inCategory($category)->coded('frets')->labelled('Frets')->create();
        $this->signInAsAdmin();
        $this->raceAttributeSave(fn () => $this->db->executeStatement(
            'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required) VALUES (?, ?, ?, ?, ?, false)',
            [Uuid::v7()->toRfc4122(), $categoryId, 'fret-count', 'The other admin\'s', 'text'],
        ));
        $before = $this->tables();

        $page = $this->submit($this->editPath($attribute), ['label' => 'Fret count', 'code' => 'fret-count']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('EntityManager is closed', (string) $this->client->getResponse()->getContent());
        self::assertSame(['“fret-count” is already used by an attribute of this category or of a category above or below it.'], $this->errorsOn($page, 'code'));
        self::assertSame('Fret count', $this->valueOf($page, 'label'));
        self::assertSame($before, $this->tables());
        self::assertSame('frets', $this->db->fetchOne('SELECT code FROM catalog_attribute WHERE id = ?', [$attribute->id()->toString()]));
        $this->assertNothingReportedToSentry();
    }

    public function testAnOptionLabelTakenAtTheIndexDuringDefineRenders422OnTheOptionsField(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();
        // The new attribute's id is minted by the handler; the attribute double remembers it so the
        // option interloper can plant a colliding row under exactly that id.
        $attributes = $this->raceAttributeSave(static fn () => null);
        $this->raceOptionSave(fn () => $this->db->executeStatement(
            'INSERT INTO catalog_attribute_option (id, attribute_id, label, label_key) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $attributes->lastMinted()->toString(), 'left', 'left'],
        ));
        $before = $this->tables();

        $page = $this->submit($this->newPath($category, 'choice'), ['label' => 'Orientation', 'code' => 'orientation', 'options' => "Left\nRight"]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString('EntityManager is closed', (string) $this->client->getResponse()->getContent());
        self::assertSame(['“Left” is listed twice.'], $this->errorsOn($page, 'options'));
        self::assertSame("Left\nRight", $this->valueOf($page, 'options'));
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    // ---------------------------------------------------------------- AC-37 edit

    public function testTheEditPageIsPrefilledAndShowsTheTypeAsReadOnlyText(): void
    {
        $attribute = AttributeFactory::new()->number(NumberRules::of(Unit::fromString('mm'), 5, 10))->coded('scale')->labelled('Scale')->with(['required' => true])->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($attribute));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Scale', $this->valueOf($page, 'label'));
        self::assertSame('scale', $this->valueOf($page, 'code'));
        self::assertSame('mm', $this->valueOf($page, 'unit'));
        self::assertSame('5', $this->valueOf($page, 'minimum'));
        self::assertSame('10', $this->valueOf($page, 'maximum'));
        self::assertNotNull($page->filter('#attribute_form_required')->attr('checked'));
        self::assertStringContainsString('Type: Number', $page->filter('body')->text());
        self::assertCount(0, $page->filter('[name="attribute_form[type]"]'), 'The type is text, not a field.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonNumberTypes(): iterable
    {
        yield 'choice' => ['choice'];
        yield 'boolean' => ['boolean'];
        yield 'text' => ['text'];
    }

    #[DataProvider('nonNumberTypes')]
    public function testNumberFieldsAppearOnlyOnTheEditPageOfANumberAttribute(string $type): void
    {
        $attribute = AttributeFactory::new()->with(['type' => \App\Domain\Catalog\ValueObject\AttributeType::from($type), 'optionLabels' => 'choice' === $type ? ['A'] : []])->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($attribute));

        foreach (['unit', 'minimum', 'maximum', 'options'] as $field) {
            self::assertCount(0, $page->filter('#attribute_form_'.$field), "$field must not be on the $type edit form.");
        }
    }

    public function testTheEditPageOfAChoiceListsEveryOptionInInsertionOrderWithItsOwnForms(): void
    {
        $attribute = AttributeFactory::new()->choice(['Zulu', 'Alpha', 'Mike'])->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($attribute));

        $labels = $page->filter('input[aria-label="Option label"]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
        self::assertSame(['Zulu', 'Alpha', 'Mike'], $labels);
        foreach (['Zulu', 'Alpha', 'Mike'] as $label) {
            $id = $this->optionId($attribute, $label);
            self::assertCount(1, $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/options/%s"]', $attribute->id()->toString(), $id)), "relabel form for $label");
            self::assertCount(1, $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/options/%s/delete"]', $attribute->id()->toString(), $id)), "remove form for $label");
        }
        self::assertCount(1, $page->filter(\sprintf('form[method="post"][action="/admin/attributes/%s/options"]', $attribute->id()->toString())));
    }

    public function testEditingThroughTheFormPersistsAndRedirectsToTheListWithAFlash(): void
    {
        $category = CategoryFactory::createOne();
        $attribute = AttributeFactory::new()->inCategory($category)->number(NumberRules::of(null, 1, 2))->coded('scale')->create();
        $this->signInAsAdmin();

        $this->submit($this->editPath($attribute), ['label' => '  Scale length ', 'code' => 'scale-length', 'required' => '1', 'unit' => 'cm', 'minimum' => '3', 'maximum' => '9']);

        self::assertResponseRedirects($this->listPath($category));
        $row = $this->db->fetchAssociative('SELECT label, code, is_required, number_rules FROM catalog_attribute WHERE id = ?', [$attribute->id()->toString()]);
        self::assertNotFalse($row);
        self::assertSame(['Scale length', 'scale-length', true], [$row['label'], $row['code'], $row['is_required']]);
        self::assertIsString($row['number_rules']);
        self::assertEquals(['unit' => 'cm', 'min' => 3, 'max' => 9], json_decode($row['number_rules'], true));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved “Scale length”.');
    }

    public function testNumberFieldsPostedToANonNumberAttributeAreRejectedAsExtraFieldsAndNothingChanges(): void
    {
        $attribute = AttributeFactory::new()->boolean()->coded('has-case')->labelled('Has case')->create();
        $this->signInAsAdmin();
        $before = $this->tables();
        $token = $this->formToken($this->editPath($attribute));

        $this->client->request('POST', $this->editPath($attribute), ['attribute_form' => ['label' => 'Renamed', 'code' => 'has-case', 'unit' => 'mm', 'minimum' => '1', 'maximum' => '2', '_token' => $token]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->tables());
    }

    public function testTheOptionsFieldPostedToAnEditIsRejectedAsAnExtraField(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();
        $token = $this->formToken($this->editPath($attribute));

        $this->client->request('POST', $this->editPath($attribute), ['attribute_form' => ['label' => 'Renamed', 'code' => 'renamed', 'options' => "Left\nRight", '_token' => $token]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->tables());
    }

    // ---------------------------------------------------------------- AC-38 option actions

    private function assertRedirectsToEditThenFlash(Attribute $attribute, string $class, string $message): void
    {
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->editPath($attribute));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.flash-'.$class, $message);
        $this->assertNothingReportedToSentry();
    }

    public function testAddingAnOptionRedirectsToTheEditPageWithASuccessFlash(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options', ['_token' => $this->optionsToken($attribute), 'label' => '  Right ']);

        $this->assertRedirectsToEditThenFlash($attribute, 'success', 'Added “Right”.');
        self::assertSame(['Left', 'Right'], $this->optionLabels($attribute));
    }

    public function testRelabellingAnOptionKeepsItsIdAndFlashesSuccess(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left', 'Right'])->create();
        $left = $this->optionId($attribute, 'Left');
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options/'.$left, ['_token' => $this->optionsToken($attribute), 'label' => 'Port']);

        $this->assertRedirectsToEditThenFlash($attribute, 'success', 'Renamed to “Port”.');
        self::assertSame($left, $this->optionId($attribute, 'Port'));
    }

    public function testRemovingAnOptionFlashesSuccess(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left', 'Right'])->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options/'.$this->optionId($attribute, 'Left').'/delete', ['_token' => $this->optionsToken($attribute)]);

        $this->assertRedirectsToEditThenFlash($attribute, 'success', 'Removed the option.');
        self::assertSame(['Right'], $this->optionLabels($attribute));
    }

    public function testAddingADuplicateLabelIsAnErrorFlashAndChangesNothing(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options', ['_token' => $this->optionsToken($attribute), 'label' => 'RIGHT']);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', '“RIGHT” is already an option.');
        self::assertSame($before, $this->tables());
    }

    public function testRelabellingToAnotherOptionsLabelIsAnErrorFlashAndChangesNothing(): void
    {
        $attribute = AttributeFactory::new()->choice(['Right', 'Left'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options/'.$this->optionId($attribute, 'Left'), ['_token' => $this->optionsToken($attribute), 'label' => 'right']);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', '“right” is already an option.');
        self::assertSame($before, $this->tables());
    }

    public function testAnInvalidOptionLabelIsAnErrorFlashAndChangesNothing(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options', ['_token' => $this->optionsToken($attribute), 'label' => str_repeat('x', 101)]);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', 'Each option must be 1–100 characters.');
        self::assertSame($before, $this->tables());
    }

    public function testRemovingTheLastOptionIsAnErrorFlashAndTheOptionRemains(): void
    {
        $attribute = AttributeFactory::new()->choice(['Only'])->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options/'.$this->optionId($attribute, 'Only').'/delete', ['_token' => $this->optionsToken($attribute)]);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', 'A choice attribute needs at least one option. Delete the attribute instead.');
        self::assertSame(['Only'], $this->optionLabels($attribute));
    }

    public function testAnOptionActionOnANonChoiceAttributeIsAnErrorFlashAndChangesNothing(): void
    {
        $attribute = AttributeFactory::new()->boolean()->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        // Only a tampered request gets here: the page offers option forms for a choice only.
        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/options', ['_token' => $this->optionsToken($attribute), 'label' => 'Sneaky']);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', 'That does not apply to this attribute type.');
        self::assertSame($before, $this->tables());
    }

    public function testALabelTakenAtTheIndexDuringAnAddIsAnErrorFlashAndThePageAfterItStillRenders(): void
    {
        $attribute = AttributeFactory::new()->choice(['Left'])->create();
        $id = $attribute->id()->toString();
        $this->signInAsAdmin();
        $token = $this->optionsToken($attribute);
        $this->raceOptionSave(fn () => $this->db->executeStatement(
            'INSERT INTO catalog_attribute_option (id, attribute_id, label, label_key) VALUES (?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $id, 'right', 'right'],
        ));
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$id.'/options', ['_token' => $token, 'label' => 'Right']);

        $this->assertRedirectsToEditThenFlash($attribute, 'error', '“Right” is already an option.');
        self::assertStringNotContainsString('EntityManager is closed', (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->tables());
    }

    public function testNoGetRouteChangesState(): void
    {
        [, $attribute] = $this->categoryWithChoice();
        $this->signInAsAdmin();
        $before = $this->tables();
        $base = '/admin/attributes/'.$attribute->id()->toString();

        foreach ([$base.'/delete', $base.'/options', $base.'/options/'.$this->optionId($attribute, 'Left'), $base.'/options/'.$this->optionId($attribute, 'Left').'/delete'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(405, $path);
        }
        self::assertSame($before, $this->tables());
    }

    // ---------------------------------------------------------------- AC-39 delete

    public function testDeletingAnAttributeRemovesItAndItsOptionsAndRedirectsToTheListWithAFlash(): void
    {
        $category = CategoryFactory::createOne();
        $doomed = AttributeFactory::new()->inCategory($category)->labelled('Orientation')->choice(['Left', 'Right'])->create();
        $kept = AttributeFactory::new()->inCategory($category)->choice(['X'])->create();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$doomed->id()->toString().'/delete', ['_token' => $this->mintToken('delete-attribute-'.$doomed->id()->toString())]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->listPath($category));
        self::assertSame(0, $this->db->fetchOne('SELECT count(*) FROM catalog_attribute WHERE id = ?', [$doomed->id()->toString()]));
        self::assertSame([], $this->optionLabels($doomed));
        self::assertSame(['X'], $this->optionLabels($kept));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Deleted “Orientation” and its options.');
    }

    // ---------------------------------------------------------------- AC-40 CSRF

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'wrong token' => ['not-the-token'];
        yield 'missing token' => [''];
    }

    #[DataProvider('badTokens')]
    public function testDefineWithABadCsrfTokenMutatesNothingAndRenders422(string $token): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();

        $this->submit($this->newPath($category, 'text'), ['label' => 'Serial', 'code' => 'serial'], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([[], []], $this->tables());
    }

    #[DataProvider('badTokens')]
    public function testEditWithABadCsrfTokenMutatesNothingAndRenders422(string $token): void
    {
        [, $attribute] = $this->categoryWithChoice();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->submit($this->editPath($attribute), ['label' => 'Hacked', 'code' => 'hacked'], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->tables());
    }

    #[DataProvider('badTokens')]
    public function testDeleteAndTheThreeOptionActionsAnswer403OnABadTokenAndMutateNothing(string $token): void
    {
        [, $attribute] = $this->categoryWithChoice();
        $this->signInAsAdmin();
        $before = $this->tables();
        $base = '/admin/attributes/'.$attribute->id()->toString();
        $left = $this->optionId($attribute, 'Left');

        foreach ([
            [$base.'/delete', []],
            [$base.'/options', ['label' => 'Sneaky']],
            [$base.'/options/'.$left, ['label' => 'Sneaky']],
            [$base.'/options/'.$left.'/delete', []],
        ] as [$path, $body]) {
            $this->client->request('POST', $path, ['_token' => $token, ...$body]);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertSame($before, $this->tables());
    }

    public function testATokenMintedForAnotherAttributeIsNotAcceptedByDeleteOrTheOptionActions(): void
    {
        $mine = AttributeFactory::new()->choice(['A', 'B'])->create();
        $other = AttributeFactory::new()->text()->create();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$mine->id()->toString().'/delete', ['_token' => $this->mintToken('delete-attribute-'.$other->id()->toString())]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/attributes/'.$mine->id()->toString().'/options', ['_token' => $this->optionsToken($other), 'label' => 'C']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->tables());
    }

    // ---------------------------------------------------------------- AC-41 unknown ids

    public function testEveryRouteAnswers404ForAnUnknownOrMalformedAttributeId(): void
    {
        $this->signInAsAdmin();
        $unknown = Uuid::v7()->toRfc4122();
        $option = Uuid::v7()->toRfc4122();
        $before = $this->tables();

        $requests = [
            ['GET', "/admin/attributes/$unknown/edit", []],
            ['POST', "/admin/attributes/$unknown/edit", ['attribute_form' => ['label' => 'X', 'code' => 'x']]],
            ['POST', "/admin/attributes/$unknown/delete", ['_token' => $this->mintToken("delete-attribute-$unknown")]],
            ['POST', "/admin/attributes/$unknown/options", ['_token' => $this->mintToken("attribute-options-$unknown"), 'label' => 'X']],
            ['POST', "/admin/attributes/$unknown/options/$option", ['_token' => $this->mintToken("attribute-options-$unknown"), 'label' => 'X']],
            ['POST', "/admin/attributes/$unknown/options/$option/delete", ['_token' => $this->mintToken("attribute-options-$unknown")]],
            ['GET', '/admin/attributes/not-a-uuid/edit', []],
            ['POST', '/admin/attributes/not-a-uuid/edit', []],
            ['POST', '/admin/attributes/not-a-uuid/delete', []],
            ['POST', '/admin/attributes/not-a-uuid/options', []],
            ['POST', "/admin/attributes/not-a-uuid/options/$option", []],
            ['POST', "/admin/attributes/$unknown/options/not-a-uuid", []],
            ['POST', "/admin/attributes/$unknown/options/not-a-uuid/delete", []],
        ];
        foreach ($requests as [$method, $path, $body]) {
            $this->client->request($method, $path, $body);
            self::assertResponseStatusCodeSame(404, "$method $path");
        }
        self::assertSame($before, $this->tables());
    }

    public function testAnUnknownOptionIdOfAKnownAttributeIs404OnRelabelAndRemove(): void
    {
        $attribute = AttributeFactory::new()->choice(['A', 'B'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();
        $base = '/admin/attributes/'.$attribute->id()->toString();
        $unknown = Uuid::v7()->toRfc4122();

        $this->client->request('POST', $base.'/options/'.$unknown, ['_token' => $this->optionsToken($attribute), 'label' => 'X']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/options/'.$unknown.'/delete', ['_token' => $this->optionsToken($attribute)]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->tables());
    }

    public function testAnOptionOfAnotherAttributeIs404OnRelabelAndRemove(): void
    {
        $mine = AttributeFactory::new()->choice(['Mine', 'Mine too'])->create();
        $theirs = AttributeFactory::new()->choice(['Theirs'])->create();
        $this->signInAsAdmin();
        $before = $this->tables();
        $foreign = $this->optionId($theirs, 'Theirs');
        $base = '/admin/attributes/'.$mine->id()->toString();

        $this->client->request('POST', $base.'/options/'.$foreign, ['_token' => $this->optionsToken($mine), 'label' => 'Hijacked']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $base.'/options/'.$foreign.'/delete', ['_token' => $this->optionsToken($mine)]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->tables());
    }

    public function testAnAttributeDeletedBetweenPageLoadAndSubmitIs404(): void
    {
        $attribute = AttributeFactory::new()->text()->create();
        $this->signInAsAdmin();
        $token = $this->mintToken('delete-attribute-'.$attribute->id()->toString());
        $this->db->executeStatement('DELETE FROM catalog_attribute WHERE id = ?', [$attribute->id()->toString()]);

        $this->client->request('POST', '/admin/attributes/'.$attribute->id()->toString().'/delete', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testACategoryDeletedBetweenPageLoadAndDefineSubmitIs404AndWritesNothing(): void
    {
        $category = CategoryFactory::createOne();
        $this->signInAsAdmin();
        $path = $this->newPath($category, 'text');
        $token = $this->formToken($path);
        // The controller's category lookup answers this 404 before any handler runs. The handler's own
        // `CategoryNotFound` arm (C-12) is only reachable by a delete landing *between* the two reads,
        // which no double here can schedule; the handler half of C-12 is AC-14 in AttributeHandlersTest.
        $this->db->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$category->id()->toString()]);

        $this->client->request('POST', $path, ['attribute_form' => ['label' => 'Serial', 'code' => 'serial', '_token' => $token]]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([[], []], $this->tables());
    }

    // ---------------------------------------------------------------- AC-42 corruption is reported

    public function testACorruptNumberRulesRowIsA500ReportedToSentryAndWritesNothing(): void
    {
        $category = CategoryFactory::createOne();
        $id = Uuid::v7()->toRfc4122();
        // Readable as JSON, unreadable as number rules: `unit` must be a string or null.
        $this->db->executeStatement(
            'INSERT INTO catalog_attribute (id, category_id, code, label, type, is_required, number_rules) VALUES (?, ?, ?, ?, ?, false, ?)',
            [$id, $category->id()->toString(), 'broken', 'Broken', 'number', '{"unit":5,"min":1,"max":2}'],
        );
        $this->signInAsAdmin();
        $before = $this->tables();
        $token = $this->formToken('/admin/attributes/'.$id.'/edit');

        $this->client->request('POST', '/admin/attributes/'.$id.'/edit', ['attribute_form' => ['label' => 'Renamed', 'code' => 'broken', 'unit' => '', 'minimum' => '1', 'maximum' => '2', '_token' => $token]]);

        self::assertResponseStatusCodeSame(500);
        self::assertSame($before, $this->tables());
        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        // Sentry has no `critical` level: Monolog's CRITICAL maps to `fatal`.
        self::assertSame('fatal', (string) $events[0]->getLevel());
    }

    // ---------------------------------------------------------------- schema inheritance (AC-23 … AC-29)

    private const string IN_BRANCH = ' is already used by an attribute of this category or of a category above or below it.';

    /**
     * Instruments (condition) > Guitars (strings) > Electric (pickups).
     *
     * @return array{Category, Category, Category, Attribute, Attribute, Attribute}
     */
    private function inheritanceTree(): array
    {
        $instruments = CategoryFactory::new(['name' => CategoryName::fromString('Instruments')])->create();
        $guitars = CategoryFactory::new(['name' => CategoryName::fromString('Guitars')])->childOf($instruments)->create();
        $electric = CategoryFactory::new(['name' => CategoryName::fromString('Electric')])->childOf($guitars)->create();

        return [
            $instruments,
            $guitars,
            $electric,
            AttributeFactory::new()->inCategory($instruments)->coded('condition')->labelled('Condition')->choice(['New', 'Used'])->create(),
            AttributeFactory::new()->inCategory($guitars)->coded('strings')->labelled('String count')->number(NumberRules::of(Unit::fromString('pcs'), 4, 12))->create(),
            AttributeFactory::new()->inCategory($electric)->coded('pickups')->labelled('Pickups')->choice(['Single', 'Humbucker'])->create(),
        ];
    }

    /**
     * @return list<string> the whitespace-normalised text of each cell of each body row
     */
    private function rowsOf(Crawler $table): array
    {
        return $table->filter('tbody tr')->each(static fn (Crawler $tr): string => preg_replace('/\s+/', ' ', trim($tr->text())) ?? '');
    }

    public function testTheListShowsDefinedHereAndInheritedSectionsWithTheInheritedRowsReadOnly(): void
    {
        [$instruments, $guitars, $electric, , , $pickups] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($electric));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Defined here', 'Inherited'], $page->filter('h2')->each(static fn (Crawler $h): string => trim($h->text())));
        $tables = $page->filter('table');
        self::assertCount(2, $tables);

        $own = $tables->eq(0);
        self::assertCount(1, $own->filter('tbody tr'));
        self::assertStringStartsWith('Pickups pickups Choice', $this->rowsOf($own)[0]);
        self::assertCount(1, $own->filter(\sprintf('a[href="%s"]', $this->editPath($pickups))));
        self::assertCount(1, $own->filter(\sprintf('form[action="/admin/attributes/%s/delete"] input[name="_token"]', $pickups->id()->toString())));

        $inherited = $tables->eq(1);
        $rows = $this->rowsOf($inherited);
        self::assertCount(2, $rows);
        self::assertStringStartsWith('Condition condition Choice', $rows[0]);
        self::assertStringContainsString('2 options', $rows[0]);
        self::assertStringEndsWith('from Instruments', $rows[0]);
        self::assertStringStartsWith('String count strings Number', $rows[1]);
        self::assertStringContainsString('unit: pcs', $rows[1]);
        self::assertStringContainsString('min: 4', $rows[1]);
        self::assertStringContainsString('max: 12', $rows[1]);
        self::assertStringEndsWith('from Guitars', $rows[1]);
        self::assertCount(1, $inherited->filter(\sprintf('a[href="%s"]', $this->listPath($instruments))));
        self::assertCount(1, $inherited->filter(\sprintf('a[href="%s"]', $this->listPath($guitars))));
        self::assertCount(0, $inherited->filter('form, button, input, a[href*="/edit"]'), 'An inherited row has no Edit, Delete or token.');
    }

    public function testARootCategoryHasNoInheritedSection(): void
    {
        [$instruments] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($instruments));

        self::assertSame(['Defined here'], $page->filter('h2')->each(static fn (Crawler $h): string => trim($h->text())));
        self::assertCount(1, $page->filter('table'));
    }

    public function testACategoryWithNoAttributesOfItsOwnSaysSoAndStillListsWhatItInherits(): void
    {
        [$instruments] = $this->inheritanceTree();
        $bass = CategoryFactory::new()->childOf($instruments)->create();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->listPath($bass));

        self::assertStringContainsString('No attributes of its own.', $page->filter('body')->text());
        $tables = $page->filter('table');
        self::assertCount(1, $tables);
        self::assertCount(1, $tables->eq(0)->filter('tbody tr'));
    }

    public function testTheOwnAndInheritedAttributesOfADeepCategoryComeFromOneQuery(): void
    {
        [, , $electric] = $this->inheritanceTree();
        $this->signInAsAdmin();
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $this->client->request('GET', $this->listPath($electric));
        } finally {
            $sql = QueryRecordingStatement::uninstall($pdo);
        }

        self::assertResponseStatusCodeSame(200);
        // listing-schema-drift (D9): the "Archived" section is its own query, `archivedDefinedOn()`,
        // and not part of the effective schema this test counts.
        $touching = array_filter($sql, static fn (string $q): bool => str_contains($q, 'catalog_attribute') && !str_contains($q, 'archived_at IS NOT NULL'));
        self::assertCount(1, $touching, "Queries on the attribute tables:\n".implode("\n", $touching));
        self::assertNotSame([], $sql, 'The recorder saw nothing, so the count above proves nothing.');
    }

    public function testDefiningACodeAnAncestorHasIsRefusedOnTheCodeField(): void
    {
        [, , $electric] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($electric, 'text'), ['label' => 'Strings', 'code' => 'strings'], 'code', '“strings”'.self::IN_BRANCH);
    }

    public function testDefiningACodeADescendantHasIsRefusedOnTheCodeField(): void
    {
        [, $guitars] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($guitars, 'text'), ['label' => 'Pickups', 'code' => 'pickups'], 'code', '“pickups”'.self::IN_BRANCH);
    }

    public function testABlankCodeDerivedToACollidingValueIsRefusedOnTheCodeField(): void
    {
        [, , $electric] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->newPath($electric, 'text'), ['label' => 'Strings', 'code' => ''], 'code', '“strings”'.self::IN_BRANCH);
    }

    public function testRecodingToACodeInForceOnTheBranchIsRefusedOnTheCodeField(): void
    {
        [, , , , , $pickups] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $this->assertRefusedOnField($this->editPath($pickups), ['label' => 'Pickups', 'code' => 'strings'], 'code', '“strings”'.self::IN_BRANCH);
    }

    public function testTheEditPageSaysWhereTheChangesApply(): void
    {
        [, , , $condition] = $this->inheritanceTree();
        $this->signInAsAdmin();

        $page = $this->client->request('GET', $this->editPath($condition));

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Changes apply to “Instruments” and every category below it.', $page->filter('body')->text());
    }

    // ---------------------------------------------------------------- listing-attribute-values AC-24 (C-25, C-26)

    /**
     * @return array{Category, Attribute}
     */
    private function categoryWithBrandInUse(): array
    {
        $category = CategoryFactory::createOne();
        $brand = AttributeFactory::new()->inCategory($category)->coded('brand')->labelled('Brand')->choice(['Fender', 'Gibson'])->create();
        ListingFactory::new()->inCategory($category)->withValues([$brand->id()->toString() => $this->optionId($brand, 'Fender')])->create();

        return [$category, $brand];
    }

    public function testDeletingAnAttributeInUseIsAnErrorFlashOnTheListAndChangesNothing(): void
    {
        [$category, $brand] = $this->categoryWithBrandInUse();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$brand->id()->toString().'/delete', ['_token' => $this->mintToken('delete-attribute-'.$brand->id()->toString())]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->listPath($category));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Listings use “Brand”, so it cannot be deleted. Archive it instead — listings keep their values.');
        self::assertSame($before, $this->tables());
        $this->assertNothingReportedToSentry();
    }

    public function testRemovingAnOptionInUseIsAnErrorFlashOnTheEditPageAndTheOptionRemains(): void
    {
        [, $brand] = $this->categoryWithBrandInUse();
        $this->signInAsAdmin();
        $before = $this->tables();

        $this->client->request('POST', '/admin/attributes/'.$brand->id()->toString().'/options/'.$this->optionId($brand, 'Fender').'/delete', ['_token' => $this->optionsToken($brand)]);

        $this->assertRedirectsToEditThenFlash($brand, 'error', 'Listings use “Fender”, so it cannot be deleted. Archive it instead.');
        self::assertSame($before, $this->tables());
        self::assertSame(['Fender', 'Gibson'], $this->optionLabels($brand));
    }

    public function testRemovingAnUnusedOptionOfAnAttributeInUseStillSucceeds(): void
    {
        [, $brand] = $this->categoryWithBrandInUse();
        $this->signInAsAdmin();

        $this->client->request('POST', '/admin/attributes/'.$brand->id()->toString().'/options/'.$this->optionId($brand, 'Gibson').'/delete', ['_token' => $this->optionsToken($brand)]);

        $this->assertRedirectsToEditThenFlash($brand, 'success', 'Removed the option.');
        self::assertSame(['Fender'], $this->optionLabels($brand));
    }
}
