<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing;

use PHPUnit\Framework\TestCase;

/**
 * AC-7 (listing-draft) and AC-5 (listing-attribute-values). Deptrac enforces layers, not contexts, so both claims below would otherwise rest on nothing.
 * Each asserts it scanned more than zero files so a wrong path cannot pass vacuously.
 */
final class ListingIsolationTest extends TestCase
{
    /**
     * @param list<string> $extensions
     *
     * @return array{int, list<string>} files scanned, matching offences
     */
    private function scan(string $dir, array $extensions, string $pattern): array
    {
        $root = \dirname(__DIR__, 4).'/src';
        $offences = [];
        $scanned = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!\in_array($file->getExtension(), $extensions, true)) {
                continue;
            }
            ++$scanned;
            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[0] as $hit) {
                $offences[] = substr($file->getPathname(), \strlen($root) + 1).': '.$hit;
            }
        }

        return [$scanned, $offences];
    }

    public function testListingDomainAndApplicationReferenceNoOtherContext(): void
    {
        $scanned = 0;
        $offences = [];
        foreach (['Domain/Listing', 'Application/Listing'] as $dir) {
            [$count, $hits] = $this->scan($dir, ['php'], '/App\\\\(?:Domain|Application|Infrastructure)\\\\(?!Listing\\\\)\w+\\\\/');
            $scanned += $count;
            $offences = [...$offences, ...$hits];
        }

        self::assertGreaterThan(0, $scanned, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testListingInfrastructureNamesNoCatalogTable(): void
    {
        [$scanned, $offences] = $this->scan('Infrastructure/Listing', ['php', 'xml'], '/\bcatalog_\w+/');

        self::assertGreaterThan(0, $scanned, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    public function testCatalogInfrastructureNamesNoListingTable(): void
    {
        [$scanned, $offences] = $this->scan('Infrastructure/Catalog', ['php', 'xml'], '/\blisting_\w+/');

        self::assertGreaterThan(0, $scanned, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }

    /**
     * The code of every PHP/XML file under src/, with PHP comments removed: a docblock that names the
     * table for the reader is documentation, a string or identifier that names it is a dependency.
     *
     * @return array<string, string> path relative to src/ => code
     */
    private function sourceWithoutComments(): array
    {
        $root = \dirname(__DIR__, 4).'/src';
        $out = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $contents = (string) file_get_contents($file->getPathname());

            if ('php' === $file->getExtension()) {
                $code = '';
                foreach (token_get_all($contents) as $token) {
                    if (\is_array($token)) {
                        $code .= \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? '' : $token[1];
                    } else {
                        $code .= $token;
                    }
                }
                $out[$relative] = $code;
            } elseif ('xml' === $file->getExtension()) {
                $out[$relative] = $contents;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $sources
     *
     * @return list<string> the files whose code matches
     */
    private function filesMatching(array $sources, string $pattern): array
    {
        return array_keys(array_filter($sources, static fn (string $code): bool => 1 === preg_match($pattern, $code)));
    }

    public function testTheValueTableIsNamedOnlyUnderListingInfrastructure(): void
    {
        $sources = $this->sourceWithoutComments();
        $hits = $this->filesMatching($sources, '/\blisting_attribute_value\b/');

        self::assertGreaterThan(0, \count($sources), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertNotSame([], $hits, 'The scan never found the table at all, so "only under Listing" proves nothing.');
        self::assertSame(
            [],
            array_values(array_filter($hits, static fn (string $path): bool => !str_starts_with($path, 'Infrastructure/Listing/'))),
        );
    }

    public function testTheCatalogAttributeTablesAreNamedOnlyUnderCatalogInfrastructure(): void
    {
        $sources = $this->sourceWithoutComments();
        $hits = $this->filesMatching($sources, '/\bcatalog_attribute\w*\b/');

        self::assertGreaterThan(0, \count($sources), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertNotSame([], $hits, 'The scan never found the tables at all, so "only under Catalog" proves nothing.');
        self::assertSame(
            [],
            array_values(array_filter($hits, static fn (string $path): bool => !str_starts_with($path, 'Infrastructure/Catalog/'))),
        );
    }

    public function testNeitherContextsDomainNorApplicationNamesAnyTable(): void
    {
        $sources = $this->sourceWithoutComments();
        $inner = array_filter($sources, static fn (string $path): bool => 1 === preg_match('#^(Domain|Application)/#', $path), \ARRAY_FILTER_USE_KEY);

        self::assertGreaterThan(0, \count($inner), 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $this->filesMatching($inner, '/\b(?:listing|catalog)_(?:attribute\w*|listing|category)\b/'));
    }
}
