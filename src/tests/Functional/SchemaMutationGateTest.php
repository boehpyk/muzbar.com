<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Factory\ListingFactory;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\MintsCsrfTokens;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use App\Tests\Support\SnapshotsTheSchemaCatalogs;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * phase1-gate: the Schema-Mutation PoC (ADR-0004's bet, PRD suggested validation #2) run end to end
 * through the HTTP doors an admin and a seller use, against the real database (DAMA).
 *
 * Section A is one scenario in one `KernelBrowser`: the admin defines "Fretboard wood" through the
 * admin form, a seller's pre-existing draft shows it with no deploy, she fills and publishes it, and
 * nothing else moved — no DDL, no pre-existing row changed. Every read-back is a fresh DBAL query,
 * never a repository (CLAUDE.md: the identity map).
 *
 * Sections B (the facet count speaks "active") and C (the index) moved to Search's tests with
 * search-browse (AC-26): `BrowseListingsTest` and `SearchIndexUsageTest`, over the same fixture
 * (`BuildsTheFacetFixture`) and the adapter's real SQL. Only the end-to-end mutation stays here.
 */
final class SchemaMutationGateTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use MintsCsrfTokens;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;
    use SnapshotsTheSchemaCatalogs;

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

    // ---------------------------------------------------------------- A. the schema mutation, end to end

    public function testTheSchemaMutationReachesTheDraftFormWithNoDdlAndNoExistingRowChanged(): void
    {
        // Fixture: the admin gets the role BEFORE any sign-in (a role change logs a signed-in user out).
        $this->buildTheValuesTree();
        $this->registerUserWithKnownCredentials('admin@example.com', self::PASSWORD, verified: true, admin: true);
        $ann = $this->registerUserWithKnownCredentials('ann@example.com', self::PASSWORD, verified: true);
        $bob = $this->registerUserWithKnownCredentials('bob@example.com', self::PASSWORD, verified: true);
        ListingFactory::new()->soldBy($bob)->inCategory($this->electric)->titled('Bob Strat')->withValues($this->allFiveElectricInputs())->published()->create();
        $draft = ListingFactory::new()->soldBy($ann)->inCategory($this->electric)->titled('Fender Precision V')->withValues($this->allFiveElectricInputs())->create();
        $draftId = $draft->id()->toString();
        $editPath = '/sell/drafts/'.$draftId.'/edit';

        // Snapshots, before AC-1 (D4, D5).
        $catalogsBefore = $this->catalogs();
        $listingsBefore = $this->listingRows();
        $valuesBefore = $this->valueRows();
        $categoriesBefore = $this->db->fetchAllAssociative('SELECT * FROM catalog_category ORDER BY id');
        $attributesBefore = $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute ORDER BY id');
        $optionsBefore = $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option ORDER BY id');

        // AC-5 control: the snapshots hold more than D, or they compare nothing.
        self::assertCount(2, $listingsBefore);
        self::assertCount(10, $valuesBefore, 'Control: Bob five values, D five values.');

        // AC-3, first half: five Details before the define; remember how they are pre-filled.
        $this->signInThroughTheLoginForm($this->client, 'ann@example.com', self::PASSWORD);
        $page = $this->client->request('GET', $editPath);
        self::assertResponseStatusCodeSame(200);
        $fieldsBefore = $this->detailFieldIds($page);
        self::assertCount(5, $fieldsBefore, 'Sanity: five Details before the define.');
        $prefilledBefore = $this->prefilled($page, $fieldsBefore);
        // A fixed reference, so "pre-filled as before" cannot pass with five blanks on both sides.
        self::assertEquals($this->allFiveElectricInputs(), $prefilledBefore, 'Sanity: D shows its five stored values.');
        $this->signOut();

        // AC-1: the admin defines the attribute through the admin form.
        $this->signInThroughTheLoginForm($this->client, 'admin@example.com', self::PASSWORD);
        $formPath = '/admin/categories/'.$this->instruments->id()->toString().'/attributes/new/choice';
        $token = $this->client->request('GET', $formPath)->filter('input[name="attribute_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', $formPath, ['attribute_form' => [
            'label' => 'Fretboard wood',
            'code' => 'fretboard-wood',
            'options' => "Rosewood\nMaple",
            '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/admin/categories/'.$this->instruments->id()->toString().'/attributes');

        $newAttributes = $this->db->fetchAllAssociative("SELECT * FROM catalog_attribute WHERE code = 'fretboard-wood'");
        self::assertCount(1, $newAttributes);
        $fretboardId = $newAttributes[0]['id'];
        self::assertIsString($fretboardId);
        self::assertSame(
            ['category_id' => $this->instruments->id()->toString(), 'label' => 'Fretboard wood', 'type' => 'choice', 'is_required' => false, 'archived_at' => null],
            array_intersect_key($newAttributes[0], array_flip(['category_id', 'label', 'type', 'is_required', 'archived_at'])),
            'AC-1: an optional, active choice attribute on Instruments.',
        );
        $newOptions = $this->db->fetchFirstColumn('SELECT label FROM catalog_attribute_option WHERE attribute_id = ? ORDER BY label', [$fretboardId]);
        self::assertSame(['Maple', 'Rosewood'], $newOptions);
        self::assertSame($categoriesBefore, $this->db->fetchAllAssociative('SELECT * FROM catalog_category ORDER BY id'));
        self::assertSame($attributesBefore, $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute WHERE id <> ? ORDER BY id', [$fretboardId]));
        self::assertSame($optionsBefore, $this->db->fetchAllAssociative('SELECT * FROM catalog_attribute_option WHERE attribute_id <> ? ORDER BY id', [$fretboardId]));
        $this->signOut();

        // AC-3, second half: six Details, the new one empty and in order; the other five as before.
        $this->signInThroughTheLoginForm($this->client, 'ann@example.com', self::PASSWORD);
        $page = $this->client->request('GET', $editPath);
        self::assertResponseStatusCodeSame(200);
        $fieldsAfter = $this->detailFieldIds($page);
        self::assertCount(6, $fieldsAfter);
        self::assertContains($fretboardId, $fieldsAfter);
        $fieldName = 'attributes_'.$fretboardId;
        self::assertSame('Fretboard wood', trim($page->filter('label[for="listing_form_'.$fieldName.'"]')->text()));
        self::assertSame('select', $page->filter('#listing_form_'.$fieldName)->nodeName());
        self::assertSame(['—', 'Rosewood', 'Maple'], $page->filter('#listing_form_'.$fieldName.' option')->each(static fn (Crawler $o): string => trim($o->text())));
        self::assertSame('', $this->prefilled($page, [$fretboardId])[$fretboardId]);
        self::assertSame($prefilledBefore, $this->prefilled($page, $fieldsBefore));

        // AC-4: Ann fills it and publishes.
        $rosewood = $this->db->fetchOne("SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = 'Rosewood'", [$fretboardId]);
        self::assertIsString($rosewood);
        $token = $page->filter('input[name="listing_form[_token]"]')->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', $editPath, [
            'listing_form' => [
                'title' => 'Fender Precision V',
                'description' => 'Mint condition',
                'price' => '1234.50',
                'currency' => 'EUR',
                'category' => $this->electric->id()->toString(),
                'attributes' => $this->allFiveElectricInputs() + [$fretboardId => $rosewood],
                '_token' => $token,
            ],
            'action' => 'publish',
        ]);
        self::assertResponseStatusCodeSame(303);
        $row = $this->db->fetchAssociative('SELECT status, published_at FROM listing_listing WHERE id = ?', [$draftId]);
        self::assertNotFalse($row);
        self::assertSame('published', $row['status']);
        self::assertNotNull($row['published_at']);
        $draftValues = $this->db->fetchAllKeyValue('SELECT attribute_id, option_id FROM listing_attribute_value WHERE listing_id = ?', [$draftId]);
        self::assertCount(6, $draftValues);
        self::assertSame($rosewood, $draftValues[$fretboardId]);

        // AC-5: every pre-existing row other than D's listing row is byte-identical (D's five values included).
        /** @param list<array<string, mixed>> $rows */
        $otherListings = static fn (array $rows): array => array_values(array_filter($rows, static fn (mixed $r): bool => \is_array($r) && $r['id'] !== $draftId));
        self::assertSame($otherListings($listingsBefore), $otherListings($this->listingRows()));
        /** @param list<array<string, mixed>> $rows */
        $withoutNewValue = static fn (array $rows): array => array_values(array_filter($rows, static fn (mixed $r): bool => \is_array($r) && $r['attribute_id'] !== $fretboardId));
        self::assertSame($valuesBefore, $withoutNewValue($this->valueRows()));

        // AC-2: no DDL, from before AC-1 to after AC-4.
        self::assertSame($catalogsBefore, $this->catalogs());
    }

    // ---------------------------------------------------------------- helpers

    private function signOut(): void
    {
        $token = $this->mintCsrfToken($this->client, 'logout');
        $this->client->request('POST', '/logout', ['_csrf_token' => $token]);
        self::assertResponseRedirects();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listingRows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_listing ORDER BY id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueRows(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value ORDER BY listing_id, attribute_id');
    }

    /**
     * @return list<string> the attribute ids of the Details fields, in page order
     */
    private function detailFieldIds(Crawler $page): array
    {
        return $page->filter('fieldset [name^="listing_form[attributes]["]')->each(static function (Crawler $node): string {
            // Never inside assert(): with zend.assertions=-1 (CI) the match is not even run.
            if (1 !== preg_match('/^listing_form\[attributes\]\[([0-9a-f-]{36})\]$/', (string) $node->attr('name'), $m)) {
                self::fail('Not a Details field name: '.$node->attr('name'));
            }

            return $m[1];
        });
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> the current value of each Details field, by attribute id
     */
    private function prefilled(Crawler $page, array $ids): array
    {
        $values = [];
        foreach ($ids as $id) {
            $node = $page->filter('#listing_form_attributes_'.$id);
            if ('select' === $node->nodeName()) {
                $values[$id] = $node->filter('option[selected]')->count() > 0 ? (string) $node->filter('option[selected]')->attr('value') : '';
            } else {
                $values[$id] = (string) $node->attr('value');
            }
        }

        return $values;
    }
}
