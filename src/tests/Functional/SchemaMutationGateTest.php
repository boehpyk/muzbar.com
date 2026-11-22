<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Identity\Entity\User;
use App\Tests\Factory\ListingFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\BuildsTheValuesTree;
use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\MintsCsrfTokens;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use App\Tests\Support\SnapshotsTheSchemaCatalogs;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
 */
final class SchemaMutationGateTest extends WebTestCase
{
    use BuildsTheValuesTree;
    use ClearsRateLimiters;
    use MintsCsrfTokens;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;
    use SnapshotsTheSchemaCatalogs;

    /**
     * The gate query (plan D1): for one browsed category and one choice attribute, `option label ->
     * number of published listings in the browsed subtree holding it`, counting only what is active.
     * Four clauses make it "active", and each needs a fixture row that only it excludes:
     *
     *  (a) `l.status = 'published'`   — a draft or withdrawn listing is not browsable (AC-7);
     *  (b) the `subtree` join         — only listings filed at or below the browsed category (AC-8);
     *  (c) `a.archived_at IS NULL`    — an archived attribute is not a facet (AC-10);
     *  (d) `o.archived_at IS NULL`    — an archived option gets no bucket; its stale values count nowhere (AC-9);
     *  (e) `a.category_id IN lineage` — the attribute must be in the browsed category's effective schema,
     *      i.e. defined on it or an ancestor (C-22; AC-11).
     *  (f) `v.attribute_id = :attribute` — the leading column of `idx_listing_attribute_value_option` (AC-13).
     *
     * (a)-(f) are lettered because the bite proof removes each in turn; keep every one on its own line.
     *
     * Dormant-by-move needs no clause of its own (AC-12): a value counted here sits on a listing in
     * the browsed subtree, and its attribute is in the browsed category's lineage; the listing's
     * category is in that subtree, so the browsed category is on the listing's own lineage, and
     * therefore so is the attribute. Subtree intersect lineage cannot hold a dormant value.
     *
     * Phase 2: lift this and AC-6...AC-11's fixture into the Search adapter.
     */
    private const string FACET_COUNT = <<<'SQL'
        WITH RECURSIVE
          subtree (id) AS (
            SELECT id FROM catalog_category WHERE id = :browsed
            UNION ALL
            SELECT c.id FROM catalog_category c JOIN subtree s ON c.parent_id = s.id
          ),
          lineage (id, parent_id) AS (
            SELECT id, parent_id FROM catalog_category WHERE id = :browsed
            UNION ALL
            SELECT p.id, p.parent_id FROM catalog_category p JOIN lineage l ON p.id = l.parent_id
          )
        SELECT o.label, count(*) AS listings
        FROM listing_attribute_value v
        JOIN listing_listing l          ON l.id = v.listing_id
                                       AND l.status = 'published'
        JOIN subtree s                  ON s.id = l.category_id
        JOIN catalog_attribute a        ON a.id = v.attribute_id
                                       AND a.archived_at IS NULL
                                       AND a.category_id IN (SELECT id FROM lineage)
        JOIN catalog_attribute_option o ON o.id = v.option_id
                                       AND o.archived_at IS NULL
        WHERE v.attribute_id = :attribute
        GROUP BY o.label
        ORDER BY o.label
        SQL;

    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;
    private Connection $db;

    private string $fretboardWood;
    private string $finish;
    private string $listingS;
    private string $listingF;

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

    // ---------------------------------------------------------------- B. the facet count speaks "active"

    /**
     * @return array<string, int> option label to number of listings, ordered by label
     */
    private function facetCount(string $browsed, string $attribute): array
    {
        $counts = [];
        foreach ($this->db->fetchAllAssociative(self::FACET_COUNT, ['browsed' => $browsed, 'attribute' => $attribute]) as $row) {
            $label = $row['label'];
            $listings = $row['listings'];
            if (!\is_string($label) || !\is_int($listings)) {
                self::fail('The gate query returned an unexpected row shape.');
            }
            $counts[$label] = $listings;
        }

        return $counts;
    }

    /**
     * Section B's own fixture (spec §B): the values tree; Fretboard wood and Finish defined through
     * the handler; then the seven listings, built after the defines, through the aggregate. Every one
     * holds Condition = Used and, on Electric, a Brand (both are required to publish).
     */
    private function buildTheFacetFixture(): void
    {
        $this->buildTheValuesTree();
        $define = self::getContainer()->get(DefineAttributeHandler::class);
        self::assertInstanceOf(DefineAttributeHandler::class, $define);
        $instruments = $this->instruments->id()->toString();
        $this->fretboardWood = $define(new DefineAttribute($instruments, 'choice', 'Fretboard wood', 'fretboard-wood', false, optionLabels: ['Rosewood', 'Maple']))->toString();
        $this->finish = $define(new DefineAttribute($instruments, 'choice', 'Finish', 'finish', false, optionLabels: ['Gloss', 'Matte']))->toString();

        $seller = UserFactory::createOne();
        $used = $this->optionIdOf($this->condition, 'Used');
        $condition = $this->condition->id()->toString();
        $fender = $this->optionIdOf($this->brand, 'Fender');
        $gibson = $this->optionIdOf($this->brand, 'Gibson');
        $rosewood = $this->wood('Rosewood');
        $maple = $this->wood('Maple');
        $gloss = $this->optionIdOf($this->finishAttribute(), 'Gloss');
        $wood = $this->fretboardWood;

        $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->published()->create(); // P1
        $this->listingOf($seller, $this->electric, $this->electricValues($gibson) + [$wood => $rosewood])->published()->create(); // P1b
        $this->listingOf($seller, $this->bass, [$condition => $used, $wood => $rosewood])->published()->create(); // P2
        $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->create(); // Dr (draft)
        $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $rosewood])->withdrawn()->create(); // W
        $this->listingS = $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$wood => $maple])->published()->create()->id()->toString();
        $this->listingF = $this->listingOf($seller, $this->electric, $this->electricValues($fender) + [$this->finish => $gloss])->published()->create()->id()->toString();
    }

    /**
     * @param array<string, string> $values
     */
    private function listingOf(User $seller, Category $category, array $values): ListingFactory
    {
        return ListingFactory::new()->soldBy($seller)->inCategory($category)->withValues($values);
    }

    /**
     * @return array<string, string> Condition = Used and the given Brand option
     */
    private function electricValues(string $brandOption): array
    {
        return [$this->condition->id()->toString() => $this->optionIdOf($this->condition, 'Used'), $this->brand->id()->toString() => $brandOption];
    }

    private function finishAttribute(): Attribute
    {
        $attribute = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $attribute);
        $found = $attribute->find(Attribute::class, AttributeId::fromString($this->finish));
        self::assertNotNull($found);

        return $found;
    }

    private function wood(string $label): string
    {
        $id = $this->db->fetchOne('SELECT id FROM catalog_attribute_option WHERE attribute_id = ? AND label = ?', [$this->fretboardWood, $label]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valueRowsOf(string $listingId): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM listing_attribute_value WHERE listing_id = ? ORDER BY attribute_id', [$listingId]);
    }

    public function testTheFacetCountFindsTheNewAttributeAtGuitarsAndAtInstruments(): void
    {
        $this->buildTheFacetFixture();

        self::assertSame(['Maple' => 1, 'Rosewood' => 2], $this->facetCount($this->guitars->id()->toString(), $this->fretboardWood));
        self::assertSame(['Maple' => 1, 'Rosewood' => 3], $this->facetCount($this->instruments->id()->toString(), $this->fretboardWood));
    }

    public function testOnlyPublishedListingsAreCounted(): void
    {
        $this->buildTheFacetFixture();

        // Control: four Rosewood values sit below Guitars (P1, P1b, Dr, W); only the two published ones count.
        self::assertSame(4, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE v.attribute_id = ? AND v.option_id = ? AND l.category_id = ?', [$this->fretboardWood, $this->wood('Rosewood'), $this->electric->id()->toString()]));
        self::assertSame(['draft', 'published', 'withdrawn'], $this->db->fetchFirstColumn('SELECT DISTINCT status FROM listing_listing ORDER BY status'));
        self::assertSame(2, $this->facetCount($this->guitars->id()->toString(), $this->fretboardWood)['Rosewood']);
    }

    public function testOnlyListingsInTheBrowsedSubtreeAreCounted(): void
    {
        $this->buildTheFacetFixture();

        $atGuitars = $this->facetCount($this->guitars->id()->toString(), $this->fretboardWood)['Rosewood'];
        $atInstruments = $this->facetCount($this->instruments->id()->toString(), $this->fretboardWood)['Rosewood'];

        // P2 (Bass) is the only difference.
        self::assertSame(1, $atInstruments - $atGuitars);
    }

    public function testAnArchivedOptionGetsNoBucketAndItsStoredValueStaysUntouched(): void
    {
        $this->buildTheFacetFixture();
        $before = $this->valueRowsOf($this->listingS);
        $handler = self::getContainer()->get(ArchiveAttributeOptionHandler::class);
        self::assertInstanceOf(ArchiveAttributeOptionHandler::class, $handler);

        $handler(new ArchiveAttributeOption($this->fretboardWood, $this->wood('Maple')));

        self::assertSame(['Rosewood' => 2], $this->facetCount($this->guitars->id()->toString(), $this->fretboardWood));
        self::assertSame($before, $this->valueRowsOf($this->listingS));
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->listingS, $this->fretboardWood]), 'Control: the stale value is still stored.');
    }

    public function testAnArchivedAttributeIsNoFacetAndItsStoredValueStaysUntouched(): void
    {
        $this->buildTheFacetFixture();
        $before = $this->valueRowsOf($this->listingF);
        $instruments = $this->instruments->id()->toString();
        self::assertSame(['Gloss' => 1], $this->facetCount($instruments, $this->finish));
        $handler = self::getContainer()->get(ArchiveAttributeHandler::class);
        self::assertInstanceOf(ArchiveAttributeHandler::class, $handler);

        $handler(new ArchiveAttribute($this->finish));

        self::assertSame([], $this->facetCount($instruments, $this->finish));
        self::assertSame($before, $this->valueRowsOf($this->listingF));
        self::assertSame(1, $this->db->fetchOne('SELECT count(*) FROM listing_attribute_value WHERE listing_id = ? AND attribute_id = ?', [$this->listingF, $this->finish]), 'Control: the dormant value is still stored.');
    }

    public function testAnAttributeIsAFacetOnlyWhereItIsInTheBrowsedCategorysLineage(): void
    {
        $this->buildTheFacetFixture();
        $brand = $this->brand->id()->toString();

        self::assertSame(['Fender' => 3, 'Gibson' => 1], $this->facetCount($this->guitars->id()->toString(), $brand));
        // Control: published listings below Instruments do hold Brand; it is just not in Instruments' schema.
        self::assertSame(4, $this->db->fetchOne("SELECT count(*) FROM listing_attribute_value v JOIN listing_listing l ON l.id = v.listing_id WHERE v.attribute_id = ? AND l.status = 'published'", [$brand]));
        self::assertSame([], $this->facetCount($this->instruments->id()->toString(), $brand));
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

    // ---------------------------------------------------------------- C. the index (keep this test last in the file)

    /**
     * AC-13: the generic index can serve the active facet query. Under `SET LOCAL` switches that
     * disable sequential scans, nested loops and merge joins (what is left is the attribute-led hash
     * join leg ADR-0004 section 2 promised the index would serve), the plan of the gate query for
     * Instruments / Fretboard wood must contain a node on `idx_listing_attribute_value_option` whose
     * Index Cond looks `attribute_id` up (a full index scan does not count), and
     * no Seq Scan on `listing_attribute_value`. `SET LOCAL` dies with DAMA's rollback, and this is
     * its own method so no other query runs under the switches.
     *
     * What this does NOT prove: which plan PostgreSQL picks on real, ANALYZEd data (a dozen
     * unanalysed rows say nothing about choice), nor any timing or latency. It proves only that no
     * clause made the value-table leg unindexable. The latency gate (10 000 rows, `EXPLAIN` on every
     * facet, no planner switches) is Phase 2's.
     */
    public function testTheGenericIndexCanServeTheActiveFacetQuery(): void
    {
        $this->buildTheFacetFixture();
        $this->db->executeStatement('SET LOCAL enable_seqscan = off');
        $this->db->executeStatement('SET LOCAL enable_nestloop = off');
        $this->db->executeStatement('SET LOCAL enable_mergejoin = off');

        $json = $this->db->fetchOne('EXPLAIN (FORMAT JSON) '.self::FACET_COUNT, ['browsed' => $this->instruments->id()->toString(), 'attribute' => $this->fretboardWood]);
        if (!\is_string($json)) {
            self::fail('EXPLAIN returned no plan.');
        }
        $plan = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $message = "The plan was:\n".json_encode($plan, \JSON_PRETTY_PRINT);

        $usesTheIndex = false;
        $seqScansTheValues = false;
        foreach ($this->planNodes(\is_array($plan) ? $plan : []) as $node) {
            // Not merely "the index appears": with seq scans disabled a FULL index scan satisfies that
            // (mutation (f) survived it), so the node must also look attribute_id up (Index Cond).
            $usesTheIndex = $usesTheIndex || ('idx_listing_attribute_value_option' === ($node['Index Name'] ?? null) && \is_string($node['Index Cond'] ?? null) && str_contains($node['Index Cond'], 'attribute_id ='));
            $seqScansTheValues = $seqScansTheValues || ('Seq Scan' === ($node['Node Type'] ?? null) && 'listing_attribute_value' === ($node['Relation Name'] ?? null));
        }

        self::assertTrue($usesTheIndex, 'No plan node uses idx_listing_attribute_value_option. '.$message);
        self::assertFalse($seqScansTheValues, 'A Seq Scan reads listing_attribute_value. '.$message);
    }

    /**
     * @param array<mixed> $plan any JSON value of an EXPLAIN plan
     *
     * @return list<array<mixed>> every plan node (an object with a "Node Type") found at any depth
     */
    private function planNodes(array $plan): array
    {
        $nodes = isset($plan['Node Type']) ? [$plan] : [];
        foreach ($plan as $child) {
            if (\is_array($child)) {
                $nodes = [...$nodes, ...$this->planNodes($child)];
            }
        }

        return $nodes;
    }
}
