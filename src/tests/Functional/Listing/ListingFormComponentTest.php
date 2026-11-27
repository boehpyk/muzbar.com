<?php

declare(strict_types=1);

namespace App\Tests\Functional\Listing;

use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Identity\Entity\User;
use App\Domain\Listing\Entity\Listing;
use App\Infrastructure\Identity\Security\SecurityUser;
use App\Infrastructure\Listing\Form\ListingFormData;
use App\Infrastructure\Listing\Form\ListingFormType;
use App\Infrastructure\Listing\Http\Component\ListingForm;
use App\Tests\Factory\AttributeFactory;
use App\Tests\Factory\ListingFactory;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\ReadsListingPages;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use App\Tests\Support\StashesTargetPath;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * listing-wizard AC-1 … AC-10, AC-19, AC-20 and AC-23: the `ListingForm` Live Component's morph,
 * driven through the real live endpoint (`POST /_components/ListingForm`) against the real database
 * (DAMA). Ann is the seller, Bob the other user; the tree is the values tree (Instruments >
 * Guitars > Electric, Instruments > Bass). `condition` (Instruments) is the one attribute Electric
 * and Bass share.
 *
 * Statement counts exclude the one `identity_user` SELECT, which is Symfony refreshing the signed-in
 * user on every authenticated request, not something the component does.
 */
final class ListingFormComponentTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use InteractsWithLiveComponents;
    use ReadsListingPages;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;
    use StashesTargetPath;

    private const string PASSWORD = 'a-correct-password-1';
    private const string MODEL = 'listing_form';

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

    private function signIn(string $email = 'ann@example.com'): User
    {
        $user = $this->registerUserWithKnownCredentials($email, self::PASSWORD, verified: true);
        $this->signInThroughTheLoginForm($this->client, $email, self::PASSWORD);

        return $user;
    }

    /** @return array<string, string> "complete D": condition = Used, brand = Fender */
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

    /**
     * The component as a page would mount it: with a form (the component refuses to exist without
     * one). The first request is itself a morph, so what this form holds hardly matters — it only
     * has to carry the stored category, as the edit page's does.
     */
    private function component(?Listing $listing = null): TestLiveComponent
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $data = new ListingFormData();
        $data->category = null === $listing ? null : $this->electric->id()->toString();
        $form = $factory->create(ListingFormType::class, $data, [
            'csrf_protection' => false, // built outside a request: no session to hold a token
            'category_choices' => [
                $this->electric->id()->toString() => 'Instruments › Guitars › Electric',
                $this->bass->id()->toString() => 'Instruments › Bass',
            ],
        ]);

        return $this->createLiveComponent('ListingForm', [
            'form' => $form->createView(),
            'listingId' => $listing?->id()->toString(),
            'buttons' => [['label' => 'Save draft', 'action' => 'save'], ['label' => 'Save and publish', 'action' => 'publish']],
        ], $this->client);
    }

    private function page(TestLiveComponent $component): Crawler
    {
        return $component->render()->crawler();
    }

    /** @param array<string, mixed> $values keys under `listing_form`, e.g. `category` or `attributes.<id>` */
    private function update(TestLiveComponent $component, array $values): void
    {
        $component->submitForm([self::MODEL => $this->nest($values)]);
    }

    /**
     * @param array<string, mixed> $dotted
     *
     * @return array<mixed>
     */
    private function nest(array $dotted): array
    {
        $nested = [];
        foreach ($dotted as $path => $value) {
            $keys = explode('.', $path);
            $leaf = array_pop($keys);
            $node = &$nested;
            foreach ($keys as $key) {
                if (!isset($node[$key]) || !\is_array($node[$key])) {
                    $node[$key] = [];
                }
                $node = &$node[$key];
            }
            $node[$leaf] = $value;
            unset($node);
        }

        return $nested;
    }

    private function valueOf(Crawler $page, string $field): string
    {
        $node = $page->filter('#listing_form_'.$field);
        self::assertCount(1, $node, 'No field '.$field);
        if ('select' === $node->nodeName()) {
            return $node->filter('option[selected]')->count() > 0 ? (string) $node->filter('option[selected]')->attr('value') : '';
        }

        return 'textarea' === $node->nodeName() ? trim($node->text('', false)) : (string) $node->attr('value');
    }

    private function detailField(Attribute $attribute): string
    {
        return 'attributes_'.$attribute->id()->toString();
    }

    /** @return list<string> the attribute ids of the Details fields, in page order */
    private function detailIds(Crawler $page): array
    {
        return array_values(array_unique($page->filter('[name^="listing_form[attributes]["]')->each(
            static function (Crawler $node): string {
                if (1 !== preg_match('/^listing_form\[attributes\]\[([^\]]+)\]/', (string) $node->attr('name'), $m)) {
                    self::fail('Not a Details field: '.$node->attr('name'));
                }

                return $m[1];
            },
        )));
    }

    private function detailsFor(Crawler $page): string
    {
        return (string) $page->filter('input[name="listing_form[details_for]"]')->attr('value');
    }

    /** @return list<string> */
    private function optionLabels(Crawler $page, string $field): array
    {
        return $page->filter('#listing_form_'.$field.' option')->each(static fn (Crawler $o): string => trim($o->text()));
    }

    /**
     * Runs `$act` with the statement recorder installed; returns the SQL it caused, minus Symfony's
     * per-request user refresh.
     *
     * @return list<array{sql: string, params: array<int, array{mixed, int}>}>
     */
    private function recorded(callable $act): array
    {
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);
        QueryRecordingStatement::install($pdo);
        try {
            $act();
        } finally {
            $statements = QueryRecordingStatement::uninstallWithValues($pdo);
        }

        return array_values(array_filter($statements, static fn (array $s): bool => !str_contains($s['sql'], 'identity_user')));
    }

    /** @param list<array{sql: string, params: array<int, array{mixed, int}>}> $statements */
    private function countOn(array $statements, string $table): int
    {
        return \count(array_filter($statements, static fn (array $s): bool => 1 === preg_match('/\b'.$table.'\b/', $s['sql'])));
    }

    private function archiveFender(): void
    {
        $handler = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $handler);
        $handler(new ArchiveAttributeOption($this->brand->id()->toString(), $this->optionIdOf($this->brand, 'Fender')));
    }

    // ---------------------------------------------------------------- AC-1

    public function testChoosingALeafRendersItsActiveEffectiveSchemaInOrderAndNamesItInDetailsFor(): void
    {
        $this->signIn();
        $component = $this->component();

        $this->update($component, ['category' => $this->electric->id()->toString()]);
        $page = $this->page($component);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            array_map(
                static fn (Attribute $a): string => $a->id()->toString(),
                [$this->condition, $this->brand, $this->hasCase, $this->serial, $this->strings],
            ),
            $this->detailIds($page),
            'One field per attribute: root first, then by label.',
        );
        self::assertSame($this->electric->id()->toString(), $this->detailsFor($page));
        self::assertSame($this->electric->id()->toString(), $this->valueOf($page, 'category'));
    }

    public function testChoosingTheBassLeafRendersBassSchemaOnly(): void
    {
        $this->signIn();
        $component = $this->component();

        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $page = $this->page($component);

        self::assertSame([$this->condition->id()->toString(), $this->bassStrings->id()->toString()], $this->detailIds($page));
        self::assertSame($this->bass->id()->toString(), $this->detailsFor($page));
    }

    public function testChoosingThePlaceholderAfterALeafRendersNoDetailsAndAnEmptyDetailsFor(): void
    {
        $this->signIn();
        $component = $this->component();
        $this->update($component, ['category' => $this->electric->id()->toString()]);

        $this->update($component, ['category' => '']);
        $page = $this->page($component);

        self::assertCount(0, $page->filter('fieldset'));
        self::assertSame([], $this->detailIds($page));
        self::assertSame('', $this->detailsFor($page));
    }

    // ---------------------------------------------------------------- AC-2

    public function testAMorphKeepsTheBasicsByteForByteEvenWhenTheDomainWouldRefuseThem(): void
    {
        $this->signIn();
        $component = $this->component();
        $title = str_repeat('T', 121);
        $description = "Line one\nLine \"two\" & <three>";

        $this->update($component, [
            'title' => $title,
            'description' => $description,
            'price' => '12,5x',
            'currency' => 'SEK',
            'category' => $this->bass->id()->toString(),
        ]);
        $page = $this->page($component);

        self::assertSame($title, $this->valueOf($page, 'title'));
        self::assertSame($description, $this->valueOf($page, 'description'));
        self::assertSame('12,5x', $this->valueOf($page, 'price'));
        self::assertSame('SEK', $this->valueOf($page, 'currency'));
        self::assertCount(0, $page->filter('.form-error, [class*="error"], li'), 'A morph shows no validation errors.');
    }

    public function testAMorphOnTheEditPageKeepsTheBasicsAsTypedNotAsStored(): void
    {
        $ann = $this->signIn();
        $draft = $this->draftOf($ann);
        $component = $this->component($draft);
        $title = str_repeat('Ж', 130);

        $this->update($component, ['title' => $title, 'description' => '', 'price' => 'abc', 'category' => $this->bass->id()->toString()]);
        $page = $this->page($component);

        self::assertSame($title, $this->valueOf($page, 'title'));
        self::assertSame('', $this->valueOf($page, 'description'));
        self::assertSame('abc', $this->valueOf($page, 'price'));
        self::assertSame($this->bass->id()->toString(), $this->valueOf($page, 'category'));
    }

    // ---------------------------------------------------------------- AC-3

    public function testAMorphKeepsTheSharedAttributesValueAndDropsEveryFieldOutsideTheNewSchema(): void
    {
        $this->signIn();
        $component = $this->component();
        $used = $this->optionIdOf($this->condition, 'Used');
        $this->update($component, ['category' => $this->electric->id()->toString()]);
        $this->update($component, [
            'attributes.'.$this->condition->id()->toString() => $used,
            'attributes.'.$this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Fender'),
            'attributes.'.$this->strings->id()->toString() => '6',
            'attributes.'.$this->serial->id()->toString() => 'SN-1',
        ]);

        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $page = $this->page($component);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($used, $this->valueOf($page, $this->detailField($this->condition)), 'The shared attribute keeps its value.');
        self::assertSame(
            [$this->condition->id()->toString(), $this->bassStrings->id()->toString()],
            $this->detailIds($page),
            'No attributes[...] key outside the new schema.',
        );
        self::assertSame('', $this->valueOf($page, $this->detailField($this->bassStrings)), 'A field never filled stays empty.');
    }

    /**
     * Mutation (f). Through the Live round trip this is invisible: `ComponentWithFormTrait` re-extracts
     * `formValues` from the form view after it submits, and clears the extra-fields error of a field
     * nobody asked to validate. So `morph()` is called directly and its own state is read.
     */
    public function testMorphLeavesNoAttributeKeyOutsideTheNewSchemaInTheStateItHandsOn(): void
    {
        $user = $this->signIn();
        $securityUser = SecurityUser::fromDomainUser($user);
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles()));
        $form = $this->component()->component();
        self::assertInstanceOf(ListingForm::class, $form);
        $form->formValues = [
            'category' => $this->bass->id()->toString(),
            'attributes' => [
                $this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'),
                $this->strings->id()->toString() => '6',
                $this->serial->id()->toString() => 'SN-1',
            ],
        ];

        $form->morph();

        self::assertIsArray($form->formValues['attributes'] ?? null);
        self::assertSame([$this->condition->id()->toString()], array_keys($form->formValues['attributes']), 'Only the new schema\'s keys survive.');
    }

    // ---------------------------------------------------------------- AC-4

    public function testBassToElectricAndBackShowsTheStoredValueAgainAndSavingKeepsIt(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->bass)->withValues([$this->bassStrings->id()->toString() => '5'])->create();
        $component = $this->component($draft);
        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $field = $this->detailField($this->bassStrings);
        self::assertSame('5', $this->valueOf($this->page($component), $field), 'Control: the stored value pre-fills.');

        $this->update($component, ['category' => $this->electric->id()->toString()]);
        self::assertNotContains($this->bassStrings->id()->toString(), $this->detailIds($this->page($component)));
        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $page = $this->page($component);
        // The page the seller started from carried the basics; the mount form here is bare, so type them.
        $this->update($component, ['title' => 'Fender Jazz Bass', 'price' => '900', 'currency' => 'EUR']);

        self::assertSame('5', $this->valueOf($page, $field), 'A field that was not on the previous render falls back to the stored value.');

        $form = (new Crawler((string) $component->response()->getContent(), 'http://localhost/_components/ListingForm'))->selectButton('Save draft')->form();
        $this->client->request('POST', '/sell/drafts/'.$draft->id()->toString().'/edit', $form->getPhpValues());
        self::assertResponseStatusCodeSame(303);
        $stored = $this->db->fetchOne('SELECT value_int FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$draft->id()->toString(), $this->bassStrings->id()->toString()]);
        self::assertSame(5, $stored, 'Saving without touching it keeps 5.');
    }

    public function testADeliberateClearOfAStoredValueIsNotUndoneByTheNextRender(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->bass)->withValues([$this->bassStrings->id()->toString() => '5'])->create();
        $component = $this->component($draft);
        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $field = $this->detailField($this->bassStrings);
        self::assertSame('5', $this->valueOf($this->page($component), $field), 'Control: the stored value pre-fills.');

        $this->update($component, ['attributes.'.$this->bassStrings->id()->toString() => '']);
        $component->refresh();

        self::assertSame('', $this->valueOf($this->page($component), $field), 'The field was on the previous render, so its empty value wins over the stored one.');
    }

    // ---------------------------------------------------------------- AC-5

    public function testAnArchivedStoredOptionStaysSelectedAndLabelledNoLongerOfferedAfterAMorphBack(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->withValues($this->allFiveElectricInputs())->create();
        $this->archiveFender();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $component = $this->component($draft);
        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $this->update($component, ['category' => $this->electric->id()->toString()]);

        $page = $this->page($component);

        self::assertSame($fender, $this->valueOf($page, $this->detailField($this->brand)));
        self::assertContains('Fender (no longer offered)', $this->optionLabels($page, $this->detailField($this->brand)));
    }

    public function testAnArchivedOptionsIdStoredAsATextValueNeverReachesTheOptionsQueryOrAnotherSelect(): void
    {
        $ann = $this->signIn();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->withValues([
            $this->brand->id()->toString() => $this->optionIdOf($this->brand, 'Gibson'),
            $this->serial->id()->toString() => $fender,
        ])->create();
        $this->archiveFender();
        $component = $this->component($draft);
        $this->page($component);

        $statements = $this->recorded(fn () => $this->update($component, ['category' => $this->electric->id()->toString()]));
        $page = $this->page($component);

        self::assertResponseStatusCodeSame(200);
        self::assertNotContains('Fender (no longer offered)', $this->optionLabels($page, $this->detailField($this->brand)));
        self::assertGreaterThanOrEqual(1, $this->countOn($statements, 'catalog_attribute_option'), 'Control: the options were read.');
        foreach ($statements as $statement) {
            foreach ($statement['params'] as [$bound]) {
                self::assertNotSame($fender, $bound, 'A text value must not be bound as a kept option id: '.$statement['sql']);
            }
        }
    }

    public function testANonUuidTextValueDoesNotBreakTheMorph(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->withValues([$this->serial->id()->toString() => 'SN-1'])->create();
        $component = $this->component($draft);

        $this->update($component, ['category' => $this->electric->id()->toString()]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SN-1', $this->valueOf($this->page($component), $this->detailField($this->serial)));
    }

    // ---------------------------------------------------------------- AC-6

    /** @return iterable<string, array{mixed}> */
    public static function categoriesNotOffered(): iterable
    {
        yield 'unknown uuid' => ['0198a000-0000-7000-8000-000000000001'];
        yield 'malformed' => ["x' OR 1=1 --"];
        yield 'not a uuid' => ['42'];
        yield 'an array' => [['a', 'b']];
        yield 'a branch' => ['@branch'];
    }

    #[DataProvider('categoriesNotOffered')]
    public function testACategoryNotAmongTheOfferedChoicesRendersNoDetailsAndReadsNoSchema(mixed $category): void
    {
        $this->signIn();
        $component = $this->component();
        $this->page($component);
        if ('@branch' === $category) {
            $category = $this->guitars->id()->toString();
        }

        $statements = $this->recorded(fn () => $this->update($component, ['category' => $category]));
        $page = $this->page($component);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('fieldset'));
        self::assertSame('', $this->detailsFor($page));
        self::assertSame(0, $this->countOn($statements, 'catalog_attribute'), 'No effectiveFor().');
        self::assertSame(0, $this->countOn($statements, 'catalog_attribute_option'), 'No optionsFor().');
    }

    public function testTheSchemaStatementsFilterOfThisClassSeesTheStatementsForAValidCategory(): void
    {
        $this->signIn();
        $component = $this->component();
        $this->page($component);

        $statements = $this->recorded(fn () => $this->update($component, ['category' => $this->electric->id()->toString()]));

        self::assertGreaterThanOrEqual(1, $this->countOn($statements, 'catalog_attribute'), 'Control for AC-6: the filter can see a schema read.');
    }

    // ---------------------------------------------------------------- AC-7

    public function testAPublishedListingsCategoryIsDisabledAndAForcedMorphChangesNothing(): void
    {
        $ann = $this->signIn();
        $published = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->withValues($this->completeDetails())->published()->create();
        $component = $this->component($published);
        $page = $this->page($component);
        self::assertNotNull($page->filter('#listing_form_category')->attr('disabled'), 'The category select is disabled (L-15).');
        $before = $this->detailIds($page);

        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $page = $this->page($component);

        self::assertSame($before, $this->detailIds($page));
        self::assertSame($this->electric->id()->toString(), $this->detailsFor($page));
        self::assertSame($this->electric->id()->toString(), $this->valueOf($page, 'category'));
    }

    // ---------------------------------------------------------------- AC-8

    public function testASellMorphIssuesAtMostThreeStatements(): void
    {
        $this->signIn();
        $component = $this->component();
        $this->page($component);

        $statements = $this->recorded(fn () => $this->update($component, ['category' => $this->electric->id()->toString()]));

        self::assertSame([], array_filter($statements, static fn (array $s): bool => 1 !== preg_match('/^\s*(SELECT|WITH)/i', $s['sql'])), 'A morph only reads.');
        self::assertNotSame([], $statements, 'The recorder saw nothing, so the count proves nothing.');
        self::assertLessThanOrEqual(3, \count($statements), implode("\n", array_column($statements, 'sql')));
    }

    public function testAnEditMorphIssuesAtMostFiveStatementsHoweverManyChoiceAttributesThereAre(): void
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->withValues($this->allFiveElectricInputs())->create();
        $component = $this->component($draft);
        $this->page($component);
        $count = fn (): int => \count($this->recorded(fn () => $this->update($component, ['category' => $this->electric->id()->toString()])));

        $few = $count();
        foreach (['finish', 'wood', 'neck'] as $code) {
            AttributeFactory::new()->inCategory($this->electric)->coded($code)->labelled(ucfirst($code))->choice(['A', 'B', 'C'])->create();
        }
        $many = $count();

        self::assertLessThanOrEqual(5, $few);
        self::assertSame($few, $many, 'Three more choice attributes must not add a statement.');
    }

    // ---------------------------------------------------------------- AC-9 (rendered) and AC-10

    public function testOnlyTheCategorySelectAsksForAMorphAndEveryFieldSyncsWithoutRendering(): void
    {
        $this->signIn();
        $component = $this->component();
        $this->update($component, ['category' => $this->electric->id()->toString()]);
        $page = $this->page($component);

        self::assertSame('norender|*', $page->filter('form')->attr('data-model'), 'Every field syncs; none renders.');
        self::assertGreaterThan(5, $page->filter('[name^="listing_form["]:not([type="hidden"])')->count(), 'Basics plus Details were on the page.');
        self::assertCount(0, $page->filter('[data-model]:not(form)'), 'No field overrides the form-wide binding.');
        $triggers = $page->filter('[data-action*="live#$render"]')->each(static fn (Crawler $n): string => (string) $n->attr('name'));
        self::assertSame(['listing_form[category]'], $triggers, 'The category select is the only element that asks for a morph.');
    }

    public function testTheMorphShowsOneStatusLineNamingTheCategoryTheDetailsAreFor(): void
    {
        $this->signIn();
        $component = $this->component();

        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $status = $this->page($component)->filter('[role="status"]');

        self::assertCount(1, $status);
        self::assertSame('Details for Instruments › Bass', trim(preg_replace('/\s+/', ' ', $status->text()) ?? ''));
    }

    public function testThereIsNoStatusLineWhenNoDetailsAreShown(): void
    {
        $this->signIn();
        $component = $this->component();

        $this->update($component, ['category' => '']);

        self::assertCount(0, $this->page($component)->filter('[role="status"]'));
    }

    // ---------------------------------------------------------------- AC-23

    public function testALiveResponseIsAFragmentWithNoLayoutNoAnalyticsAndNoUrlBoundProp(): void
    {
        $this->signIn();
        $component = $this->component();

        $this->update($component, ['category' => $this->bass->id()->toString()]);
        $body = (string) $component->response()->getContent();

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('data-live-props-value', $body, 'Control: this is the component.');
        self::assertStringNotContainsStringIgnoringCase('<html', $body);
        self::assertStringNotContainsStringIgnoringCase('<body', $body);
        self::assertStringNotContainsStringIgnoringCase('<head', $body);
        self::assertStringNotContainsStringIgnoringCase('umami', $body);
        self::assertStringNotContainsString('data-live-query-mapping-value', $body, 'No LiveProp is URL-bound, so a morph records no page view.');
    }

    // ---------------------------------------------------------------- AC-19, AC-20

    /**
     * @return array{array<string, mixed>, Listing} the component's props as Ann's edit page renders them, and her draft
     */
    private function annsLiveProps(): array
    {
        $ann = $this->signIn();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->titled('Ann private title')->withValues($this->allFiveElectricInputs())->create();
        $page = $this->client->request('GET', '/sell/drafts/'.$draft->id()->toString().'/edit');
        self::assertResponseStatusCodeSame(200);
        $json = $page->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($json);
        /** @var array<string, mixed> $props */
        $props = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return [$props, $draft];
    }

    private function exceptionHeading(string $debugPage): string
    {
        return trim(preg_replace('/\s+/', ' ', (new Crawler($debugPage))->filter('h1')->first()->text()) ?? '');
    }

    /** @param array<string, mixed> $props */
    private function liveRender(array $props): string
    {
        $this->client->catchExceptions(true);
        $this->client->request('POST', '/_components/ListingForm', ['data' => json_encode(['props' => $props, 'updated' => []], \JSON_THROW_ON_ERROR)]);

        return (string) $this->client->getResponse()->getContent();
    }

    public function testAnotherUsersReplayOfAListingsPropsIsTheSame404AsAnUnknownIdAndLeaksNothing(): void
    {
        [$props, $draft] = $this->annsLiveProps();
        $id = $draft->id()->toString();

        // Control: the owner can render her own component from these props.
        $owned = $this->liveRender($props);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Ann private title', $owned);

        // Bob replays the very same, validly signed props.
        $this->client->getCookieJar()->clear();
        $this->signIn('bob@example.com');
        $replayed = $this->liveRender($props);
        self::assertResponseStatusCodeSame(404);

        // The same props for a listing that does not exist, rendered by its (former) owner.
        $this->db->executeStatement('DELETE FROM listing_attribute_value WHERE listing_id = ?', [$id]);
        $this->db->executeStatement('DELETE FROM listing_listing WHERE id = ?', [$id]);
        $this->client->getCookieJar()->clear();
        $this->signInThroughTheLoginForm($this->client, 'ann@example.com', self::PASSWORD);
        $unknown = $this->liveRender($props);
        self::assertResponseStatusCodeSame(404);

        // The test-env debug page embeds the test's own call trace, so compare what the page says.
        self::assertSame($this->exceptionHeading($unknown), $this->exceptionHeading($replayed), 'Someone else\'s listing and a missing one are the same response.');
        self::assertNotSame('', $this->exceptionHeading($replayed), 'Control: the heading was found.');
        self::assertStringNotContainsString('data-live-props-value', $replayed, 'The 404 renders no component.');
        self::assertStringNotContainsString('Edit listing', $replayed);
    }

    /**
     * The 404-versus-302 status is what bites mutation (q) (an AccessDeniedException would be the
     * firewall's 302). The target-path line is belt-and-braces only: a POST never stashes a target
     * path, so it cannot fail on its own.
     */
    public function testAnAnonymousLiveRequestIs404AndLeavesTheIntendedActionUntouched(): void
    {
        [$props] = $this->annsLiveProps();
        $this->client->getCookieJar()->clear();
        $this->stashTargetPathDirectly($this->client, '/sell');

        $this->liveRender($props);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('/sell', $this->client->getRequest()->getSession()->get('_security.main.target_path'), 'A 404, not an entry point that overwrites the target path.');
    }
}
