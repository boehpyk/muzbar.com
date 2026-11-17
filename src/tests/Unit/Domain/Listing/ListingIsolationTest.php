<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing;

use PHPUnit\Framework\TestCase;

/**
 * AC-7. Deptrac enforces layers, not contexts, so both claims below would otherwise rest on nothing.
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
}
