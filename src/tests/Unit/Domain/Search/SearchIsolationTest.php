<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use PHPUnit\Framework\TestCase;

/**
 * search-browse AC-5 (ADR-0023's fence). Deptrac enforces layers, not contexts, so each claim below
 * would otherwise rest on nothing. Every scan asserts it read more than zero files, so a wrong path
 * cannot pass vacuously.
 */
final class SearchIsolationTest extends TestCase
{
    private const string WRITE_VERBS = '/\b(?:INSERT|UPDATE|DELETE|TRUNCATE|ALTER|CREATE|DROP|MERGE)\b/';

    /** S-16: the adapter calls only `search_tsquery()` and `search_fold()`; these build a query from, or fold, a string in PHP's SQL. */
    private const string QUERY_FUNCTIONS = '/\b(?:to_tsquery|plainto_tsquery|websearch_to_tsquery|phraseto_tsquery|unaccent)\s*\(/i';

    /**
     * @return list<\SplFileInfo> every `.php` file under `src/<dir>`
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4).'/src/'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** PHP comments removed: a docblock that names `seller_id` to forbid it is documentation, not a read. */
    private function codeOf(\SplFileInfo $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (\is_array($token)) {
                $code .= \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? '' : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function testSearchDomainAndApplicationReferenceNoOtherContext(): void
    {
        $scanned = 0;
        $offences = [];

        foreach (['Domain/Search', 'Application/Search'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                ++$scanned;
                // `Shared` is the shared kernel (events, Clock, UuidIdShape), not a bounded context.
                preg_match_all('/App\\\\(?:Domain|Application|Infrastructure)\\\\(?!Search\\\\|Shared\\\\)\w+\\\\/', (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[0] as $hit) {
                    $offences[] = $file->getFilename().': '.$hit;
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testSearchInfrastructureWritesNothing(): void
    {
        $files = $this->phpFiles('Infrastructure/Search');
        $offences = [];

        foreach ($files as $file) {
            preg_match_all(self::WRITE_VERBS, $this->codeOf($file), $m);
            foreach ($m[0] as $hit) {
                $offences[] = $file->getFilename().': '.$hit;
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testSearchInfrastructureNeverSelectsStar(): void
    {
        $files = $this->phpFiles('Infrastructure/Search');
        $offences = [];

        foreach ($files as $file) {
            // `SELECT l.*` would read seller_id without ever naming it; the scan above cannot see that.
            preg_match_all('/\bSELECT\s+\*|\b\w+\.\*/i', $this->codeOf($file), $m);
            foreach ($m[0] as $hit) {
                $offences[] = $file->getFilename().': '.$hit;
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testTheWriteVerbPatternBitesOnSqlAndSparesCamelCaseIdentifiers(): void
    {
        self::assertSame(1, preg_match(self::WRITE_VERBS, "\$c->executeStatement('DELETE FROM x')"));
        self::assertSame(1, preg_match(self::WRITE_VERBS, 'INSERT INTO x'));
        self::assertSame(0, preg_match(self::WRITE_VERBS, '$this->createNotFoundException()'));
        self::assertSame(0, preg_match(self::WRITE_VERBS, 'FILTER_VALIDATE_INT'));
    }

    public function testSearchInfrastructureNeverNamesSellerId(): void
    {
        $files = $this->phpFiles('Infrastructure/Search');
        $offences = [];

        foreach ($files as $file) {
            if (str_contains($this->codeOf($file), 'seller_id')) {
                $offences[] = $file->getFilename();
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testTheSearchTemplateNeverNamesSellerData(): void
    {
        $template = \dirname(__DIR__, 4).'/templates/search/browse.html.twig';

        self::assertFileExists($template);
        // Twig comments removed, like PHP's above: the template's own `{# … #}` explains the rule by naming it.
        self::assertStringNotContainsString('seller', preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($template)) ?? '');
    }

    public function testSearchInfrastructureNeverCallsAQueryBuilderOrUnaccentDirectly(): void
    {
        $files = $this->phpFiles('Infrastructure/Search');
        $offences = [];

        foreach ($files as $file) {
            preg_match_all(self::QUERY_FUNCTIONS, $this->codeOf($file), $m);
            foreach ($m[0] as $hit) {
                $offences[] = $file->getFilename().': '.$hit;
            }
        }

        self::assertGreaterThan(0, \count($files), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences, 'ADR-0025: only search_tsquery()/search_fold() — the fold can turn a letter into tsquery syntax.');
    }

    public function testTheQueryFunctionPatternBitesOnEachForbiddenCallAndSparesTheTwoAllowedOnes(): void
    {
        foreach (['to_tsquery', 'plainto_tsquery', 'websearch_to_tsquery', 'phraseto_tsquery', 'unaccent', 'TO_TSQUERY', 'unaccent ('] as $call) {
            self::assertSame(1, preg_match(self::QUERY_FUNCTIONS, "t.document @@ {$call}(:q)"), $call.' must be caught.');
        }
        self::assertSame(0, preg_match(self::QUERY_FUNCTIONS, 't.document @@ search_tsquery(:q)'));
        self::assertSame(0, preg_match(self::QUERY_FUNCTIONS, 't.title_folded %> search_fold(:q)'));
        self::assertSame(0, preg_match(self::QUERY_FUNCTIONS, 'search_unaccent_rules'));
    }

    public function testTheTextTableIsNamedOnlyUnderInfrastructureSearch(): void
    {
        $root = \dirname(__DIR__, 4).'/src/';
        $inSearch = 0;
        $offences = [];

        foreach ($this->phpFiles('') as $file) {
            if (!str_contains($this->codeOf($file), 'search_listing_text')) {
                continue;
            }
            if (str_starts_with($file->getPathname(), $root.'Infrastructure/Search/')) {
                ++$inSearch;
            } else {
                $offences[] = substr($file->getPathname(), \strlen($root));
            }
        }

        self::assertGreaterThan(0, $inSearch, 'The scan never saw the table in Infrastructure/Search: the path or the name is wrong, so it would pass vacuously.');
        self::assertSame([], $offences, 'A table name appears only in its owner\'s Infrastructure (ADR-0019 d.4, ADR-0025).');
    }
}
