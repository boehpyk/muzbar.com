<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Domain\Search\ValueObject\FilterRequest;
use App\Tests\Support\SeedsTheBenchmark;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * search-benchmark AC-3 (scaled), AC-4, AC-5 and the failure contract of `muzbar:search:seed-benchmark`,
 * against the real `muzbar_test` database inside DAMA, at N = 60.
 *
 * A seed costs about a minute whatever N is (10 900 Brand options, each its own locked transaction), so
 * the tests are grouped to seed as few times as possible: DAMA rolls back between tests, never inside one.
 *
 * Zipf skew (largest leaf >= 10 x median, top-10 brands >= 30 %) needs a large N to mean anything and
 * is left to the local/box run (T9/T10).
 */
final class SeedSearchBenchmarkTest extends WebTestCase
{
    use SeedsTheBenchmark;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        // debug on would keep every SQL statement and backtrace of the seed in memory (256 MB limit).
        $this->client = static::createClient(['debug' => false]);
        $this->client->disableReboot();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    public function testTheSeededDatabaseHasTheSpecifiedShapeAndRefusesASecondRun(): void
    {
        $run = $this->seed($this->client->getKernel());
        self::assertSame(0, $run['exit'], $run['stderr']);

        self::assertSame(
            ['draft' => 6, 'published' => 60, 'withdrawn' => 3],
            $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM listing_listing GROUP BY status ORDER BY status'),
        );
        self::assertSame(5, $this->int('SELECT COUNT(*) FROM catalog_category WHERE parent_id IS NULL'));
        self::assertGreaterThanOrEqual(40, $this->int('SELECT COUNT(*) FROM catalog_category c WHERE NOT EXISTS (SELECT 1 FROM catalog_category k WHERE k.parent_id = c.id)'));
        self::assertSame(4, $this->int(
            'WITH RECURSIVE t(id, depth) AS (SELECT id, 1 FROM catalog_category WHERE parent_id IS NULL UNION ALL SELECT c.id, t.depth + 1 FROM catalog_category c JOIN t ON c.parent_id = t.id) SELECT MAX(depth) FROM t',
        ), 'The deepest branch is exactly MAX_DEPTH.');
        self::assertGreaterThanOrEqual(20, $this->int(
            "WITH RECURSIVE l(leaf, node) AS (
                 SELECT c.id, c.id FROM catalog_category c
                 UNION ALL SELECT l.leaf, c.parent_id FROM l JOIN catalog_category c ON c.id = l.node WHERE c.parent_id IS NOT NULL
             )
             SELECT MAX(n) FROM (
                 SELECT l.leaf, COUNT(a.id) AS n FROM l JOIN catalog_attribute a ON a.category_id = l.node
                 WHERE a.type IN ('choice', 'boolean', 'number') GROUP BY l.leaf
             ) s",
        ));
        self::assertCount(2, $this->db->fetchFirstColumn("SELECT COUNT(*) FROM catalog_attribute a JOIN catalog_attribute_option o ON o.attribute_id = a.id WHERE a.label = 'Brand' GROUP BY a.id HAVING COUNT(*) = 5000"));
        self::assertSame(['EUR', 'SEK', 'USD'], $this->db->fetchFirstColumn('SELECT DISTINCT price_currency FROM listing_listing ORDER BY 1'));

        $before = $this->rowCounts();
        $second = $this->seed($this->client->getKernel());

        self::assertSame(1, $second['exit']);
        self::assertStringContainsString('not empty', $second['stderr']);
        self::assertSame($before, $this->rowCounts());
    }

    public function testANonNumericListingsOptionIsRefusedBeforeAnyWrite(): void
    {
        $run = $this->seed($this->client->getKernel(), listings: 'lots');

        self::assertSame(1, $run['exit']);
        self::assertStringContainsString('--listings', $run['stderr']);
        self::assertSame(0, array_sum($this->rowCounts()));
    }

    public function testTheSameSeedGivesTheSameCountsPerCategoryOptionLabelAndCurrency(): void
    {
        // Two runs into empty databases cannot share a DAMA transaction, so the first is undone with a
        // savepoint: both maps are read live and compared against each other.
        $this->db->executeStatement('SAVEPOINT before_first_seed');
        self::assertSame(0, $this->seed($this->client->getKernel())['exit']);
        $first = $this->countMaps();
        $this->db->executeStatement('ROLLBACK TO SAVEPOINT before_first_seed');
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        self::assertSame(0, array_sum($this->rowCounts()), 'The savepoint rollback did not empty the database.');
        self::assertSame(0, $this->seed($this->client->getKernel())['exit']);

        self::assertNotSame([], $first['category']);
        self::assertSame($first, $this->countMaps());
    }

    public function testEveryScenarioUrlIsRealAndB7AndB9HaveTheirSpecialShape(): void
    {
        $run = $this->seed($this->client->getKernel());
        self::assertSame(0, $run['exit'], $run['stderr']);
        /** @var array<string, list<string>> $scenarios */
        $scenarios = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);

        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9'] as $name) {
            self::assertNotEmpty($scenarios[$name] ?? [], "$name has no URL");
        }

        foreach ($scenarios as $name => $urls) {
            foreach ($urls as $url) {
                $crawler = $this->client->request('GET', $url);
                self::assertResponseStatusCodeSame(200, "$name $url");
                self::assertGreaterThanOrEqual(1, $crawler->filter('li.search-hit')->count(), "$name has no hit: $url");
                $this->assertSelectionsSurvived($crawler, $url);
                if ('B7' === $name) {
                    self::assertGreaterThanOrEqual(1, $crawler->filter('p.search-similar')->count(), "No similar-match notice: $url");
                }
            }
        }

        self::assertCount(1, $scenarios['B9']);
        parse_str((string) parse_url($scenarios['B9'][0], \PHP_URL_QUERY), $query);
        $ids = [];
        foreach (['f', 'r'] as $key) {
            $group = $query[$key] ?? [];
            self::assertIsArray($group);
            array_push($ids, ...array_keys($group));
        }
        self::assertCount(FilterRequest::MAX_ATTRIBUTES, array_unique($ids));
    }

    /**
     * An unknown option id or a malformed bound is silently dropped by `FilterRequest`/`FacetedQuery` and the
     * page still answers 200 with hits, so every selection in the URL must come back ticked / filled in.
     */
    private function assertSelectionsSurvived(Crawler $crawler, string $url): void
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $f = \is_array($query['f'] ?? null) ? $query['f'] : [];
        $r = \is_array($query['r'] ?? null) ? $query['r'] : [];

        $wanted = 0;
        foreach ($f as $values) {
            self::assertIsArray($values);
            $wanted += \count($values);
        }
        self::assertCount($wanted, $crawler->filter('.search-facets input[type=checkbox][checked]'), "A facet selection was dropped: $url");

        $price = \is_array($query['price'] ?? null) ? $query['price'] : [];
        if ([] !== $price) {
            self::assertSame($price['currency'], $crawler->filter('select[name="price[currency]"] option[selected]')->attr('value'), "The price currency was dropped: $url");
        }
        foreach (['min', 'max'] as $edge) {
            if (isset($price[$edge])) {
                self::assertSame($price[$edge], $crawler->filter(\sprintf('input[name="price[%s]"]', $edge))->attr('value'), "A price bound was dropped: $url");
            }
        }

        foreach ($r as $id => $bounds) {
            self::assertIsArray($bounds);
            foreach ($bounds as $edge => $value) {
                $input = $crawler->filter(\sprintf('input[name="r[%s][%s]"]', $id, $edge));
                self::assertCount(1, $input, "A range input is missing: $url");
                self::assertSame($value, $input->attr('value'), "A range bound was dropped: $url");
            }
        }
    }

    private function int(string $sql): int
    {
        $value = $this->db->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return array<string, int>
     */
    private function map(string $sql): array
    {
        $map = [];
        foreach ($this->db->fetchAllKeyValue($sql) as $key => $n) {
            self::assertIsNumeric($n);
            $map[(string) $key] = (int) $n;
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['catalog_category', 'catalog_attribute', 'catalog_attribute_option', 'listing_listing', 'listing_attribute_value'] as $table) {
            $counts[$table] = $this->int("SELECT COUNT(*) FROM $table");
        }

        return $counts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function countMaps(): array
    {
        return [
            'category' => $this->map('SELECT c.name, COUNT(*) FROM listing_listing l JOIN catalog_category c ON c.id = l.category_id GROUP BY c.name ORDER BY c.name'),
            'option' => $this->map('SELECT o.label, COUNT(*) FROM listing_attribute_value v JOIN catalog_attribute_option o ON o.id = v.option_id GROUP BY o.label ORDER BY o.label'),
            'currency' => $this->map('SELECT price_currency, COUNT(*) FROM listing_listing GROUP BY price_currency ORDER BY 1'),
        ];
    }
}
