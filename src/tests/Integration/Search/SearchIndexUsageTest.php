<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Application\Search\Handler\BrowseListingsHandler;
use App\Application\Search\Query\BrowseListings;
use App\Tests\Fixture\QueryRecordingStatement;
use App\Tests\Support\BuildsTheFacetFixture;
use App\Tests\Support\BuildsTheRangeFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * search-browse AC-18: the generic `(attribute_id, option_id)` index can serve the adapter's **own**
 * statements. The handler is driven with `QueryRecordingStatement` installed, the facet-count and
 * the hits-with-a-selection statements are picked out of what it sent, and each is replayed — same SQL,
 * same bound values — as `EXPLAIN (FORMAT JSON)` under `SET LOCAL enable_seqscan = off,
 * enable_nestloop = off, enable_mergejoin = off` (what is left is the attribute-led hash-join leg
 * ADR-0004 §2 promised the index would serve).
 *
 * - Facet counts: some plan node is on `idx_listing_attribute_value_option` and its `Index Cond` looks
 *   `attribute_id =` up. Not merely "the index appears": with seq scans off a FULL index scan satisfies
 *   that (phase1-gate's mutation (f) survived it until this check existed).
 * - Both statements: no `Seq Scan` on `listing_attribute_value`.
 *
 * `SET LOCAL` dies with DAMA's rollback (and so does the `ANALYZE` of the four tables, which pins the
 * planner's statistics to the fixture instead of to autovacuum's history); each statement has its own
 * test method so nothing else runs under the switches.
 *
 * search-range-facets AC-19 repeats both over the range fixture: Electric with `r[Strings][min]=1` and
 * Brand = Gibson, so the count statement with Strings' range omitted is replayed too. No claim is made
 * about `listing_listing`'s price predicate: it is unindexed on purpose (`search-benchmark` decides).
 *
 * What this does NOT prove: which plan PostgreSQL picks on real, ANALYZEd data (a dozen unanalysed
 * rows say nothing about choice), nor any timing. It proves only that no clause makes the value-table
 * leg unindexable. The latency budget, 10 000 rows and planner choice without switches are
 * `search-benchmark`'s.
 */
final class SearchIndexUsageTest extends KernelTestCase
{
    use BuildsTheFacetFixture;
    use BuildsTheRangeFixture;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = $this->facetDb();
    }

    /**
     * Browses Guitars with Brand = Gibson selected, recording every statement sent.
     *
     * @return list<array{sql: string, params: array<int, array{mixed, int}>}>
     */
    private function recordedBrowse(): array
    {
        $this->buildTheFacetFixture();
        $handler = self::getContainer()->get(BrowseListingsHandler::class);
        self::assertInstanceOf(BrowseListingsHandler::class, $handler);
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $handler(new BrowseListings($this->guitars->id()->toString(), [$this->brand->id()->toString() => [$this->optionIdOf($this->brand, 'Gibson')]], [], ['currency' => null, 'min' => null, 'max' => null], 1));
        } finally {
            $statements = QueryRecordingStatement::uninstallWithValues($pdo);
        }

        return $statements;
    }

    /**
     * @param list<array{sql: string, params: array<int, array{mixed, int}>}> $statements
     * @param callable(string): bool                                          $matches
     *
     * @return list<array{sql: string, params: array<int, array{mixed, int}>}>
     */
    private function picked(array $statements, callable $matches): array
    {
        return array_values(array_filter($statements, static fn (array $s): bool => $matches($s['sql'])));
    }

    /**
     * @param array{sql: string, params: array<int, array{mixed, int}>} $statement
     *
     * @return array<mixed> the decoded `EXPLAIN (FORMAT JSON)` plan of that statement with its recorded values
     */
    private function explained(array $statement): array
    {
        $values = [];
        $types = [];
        ksort($statement['params']);
        foreach ($statement['params'] as [$value, $pdoType]) {
            $values[] = $value;
            $types[] = \PDO::PARAM_INT === $pdoType ? ParameterType::INTEGER : ParameterType::STRING;
        }
        // The planner's statistics for these tables depend on what autovacuum last saw of every earlier test's
        // rolled-back rows, so without this the plan (pkey vs generic index on a dozen rows, where their costs tie)
        // flips between runs. ANALYZE inside DAMA's transaction describes exactly the fixture and is rolled back.
        foreach (['listing_listing', 'listing_attribute_value', 'catalog_attribute_option', 'catalog_category'] as $table) {
            $this->db->executeStatement('ANALYZE '.$table);
        }
        $this->db->executeStatement('SET LOCAL enable_seqscan = off');
        $this->db->executeStatement('SET LOCAL enable_nestloop = off');
        $this->db->executeStatement('SET LOCAL enable_mergejoin = off');

        $json = $this->db->fetchOne('EXPLAIN (FORMAT JSON) '.$statement['sql'], $values, $types);
        if (!\is_string($json)) {
            self::fail('EXPLAIN returned no plan.');
        }
        $plan = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($plan) ? $plan : [];
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

    /**
     * @param array<mixed> $plan
     */
    private function seqScansTheValues(array $plan): bool
    {
        foreach ($this->planNodes($plan) as $node) {
            if ('Seq Scan' === ($node['Node Type'] ?? null) && 'listing_attribute_value' === ($node['Relation Name'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $plan
     */
    private function looksUpAttributeIdInTheGenericIndex(array $plan): bool
    {
        foreach ($this->planNodes($plan) as $node) {
            if ('idx_listing_attribute_value_option' === ($node['Index Name'] ?? null) && \is_string($node['Index Cond'] ?? null) && str_contains($node['Index Cond'], 'attribute_id =')) {
                return true;
            }
        }

        return false;
    }

    public function testEveryFacetCountStatementCanLookUpTheAttributeInTheGenericIndex(): void
    {
        $counts = $this->picked($this->recordedBrowse(), static fn (string $sql): bool => 1 === preg_match('/FROM listing_attribute_value v\b/', $sql));

        // One statement for the unselected facets, one for the selected one: both are replayed.
        self::assertCount(2, $counts, 'The recorder did not see the two count statements, so there is nothing to replay.');
        foreach ($counts as $statement) {
            $plan = $this->explained($statement);
            $message = "\nThe statement was:\n".$statement['sql']."\nThe plan was:\n".json_encode($plan, \JSON_PRETTY_PRINT);

            self::assertTrue($this->looksUpAttributeIdInTheGenericIndex($plan), 'No plan node looks attribute_id up in idx_listing_attribute_value_option.'.$message);
            self::assertFalse($this->seqScansTheValues($plan), 'A Seq Scan reads listing_attribute_value.'.$message);
        }
    }

    public function testTheHitsStatementWithASelectionNeverSeqScansTheValueTable(): void
    {
        $hits = $this->picked($this->recordedBrowse(), static fn (string $sql): bool => str_contains($sql, 'ORDER BY l.published_at') && str_contains($sql, 'EXISTS'));

        self::assertCount(1, $hits, 'The recorder did not see the hits statement with a selection, so there is nothing to replay.');
        $plan = $this->explained($hits[0]);

        self::assertFalse($this->seqScansTheValues($plan), "A Seq Scan reads listing_attribute_value.\nThe statement was:\n".$hits[0]['sql']."\nThe plan was:\n".json_encode($plan, \JSON_PRETTY_PRINT));
        self::assertNotSame([], $this->planNodes($plan), 'The plan had no nodes: the EXPLAIN output was not read.');
    }

    /**
     * Electric with `r[Strings][min]=1` and Brand = Gibson, recording every statement sent.
     *
     * @return list<array{sql: string, params: array<int, array{mixed, int}>}>
     */
    private function recordedRangeBrowse(): array
    {
        $this->buildTheRangeFixture();
        $handler = self::getContainer()->get(BrowseListingsHandler::class);
        self::assertInstanceOf(BrowseListingsHandler::class, $handler);
        $pdo = $this->db->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $pdo);

        QueryRecordingStatement::install($pdo);
        try {
            $handler(new BrowseListings(
                $this->electric->id()->toString(),
                [$this->brand->id()->toString() => [$this->optionIdOf($this->brand, 'Gibson')]],
                [$this->strings->id()->toString() => ['min' => '1', 'max' => null]],
                ['currency' => null, 'min' => null, 'max' => null],
                1,
            ));
        } finally {
            $statements = QueryRecordingStatement::uninstallWithValues($pdo);
        }

        return $statements;
    }

    public function testEveryCountStatementOfARangeBrowseCanLookUpTheAttributeInTheGenericIndex(): void
    {
        $counts = $this->picked($this->recordedRangeBrowse(), static fn (string $sql): bool => 1 === preg_match('/FROM listing_attribute_value v\b/', $sql));

        // Unselected facets (both selections applied), then Brand's (range applied), then Strings' (range omitted).
        self::assertCount(3, $counts, 'The recorder did not see the three count statements, so there is nothing to replay.');
        self::assertCount(2, array_filter($counts, static fn (array $s): bool => str_contains($s['sql'], 'value_int >=')), 'Two of them carry the range; the one for Strings omits it.');
        foreach ($counts as $statement) {
            $plan = $this->explained($statement);
            $message = "\nThe statement was:\n".$statement['sql']."\nThe plan was:\n".json_encode($plan, \JSON_PRETTY_PRINT);

            self::assertTrue($this->looksUpAttributeIdInTheGenericIndex($plan), 'No plan node looks attribute_id up in idx_listing_attribute_value_option.'.$message);
            self::assertFalse($this->seqScansTheValues($plan), 'A Seq Scan reads listing_attribute_value.'.$message);
        }
    }

    public function testTheHitsStatementWithARangeNeverSeqScansTheValueTable(): void
    {
        $hits = $this->picked($this->recordedRangeBrowse(), static fn (string $sql): bool => str_contains($sql, 'ORDER BY l.published_at') && str_contains($sql, 'value_int >='));

        self::assertCount(1, $hits, 'The recorder did not see the hits statement with a range, so there is nothing to replay.');
        $plan = $this->explained($hits[0]);

        self::assertFalse($this->seqScansTheValues($plan), "A Seq Scan reads listing_attribute_value.\nThe statement was:\n".$hits[0]['sql']."\nThe plan was:\n".json_encode($plan, \JSON_PRETTY_PRINT));
        self::assertNotSame([], $this->planNodes($plan), 'The plan had no nodes: the EXPLAIN output was not read.');
    }
}
