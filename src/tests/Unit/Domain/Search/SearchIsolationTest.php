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
}
