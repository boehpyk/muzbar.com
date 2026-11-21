<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog;

use PHPUnit\Framework\TestCase;

/**
 * AC-7, third clause: `Domain/Catalog` and `Application/Catalog` reference no other bounded context.
 * Deptrac only enforces layers, not contexts, so without this scan the claim would rest on nothing.
 * It matches any `App\<Layer>\<Context>\` reference — `use`, fully-qualified or in a docblock.
 */
final class CatalogIsolationTest extends TestCase
{
    public function testCatalogDomainAndApplicationReferenceNoOtherContext(): void
    {
        $root = \dirname(__DIR__, 4).'/src';
        $offences = [];
        $scanned = 0;

        foreach (['Domain/Catalog', 'Application/Catalog'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                ++$scanned;
                // `Shared` is the shared kernel (events, Clock), not a bounded context.
                preg_match_all('/App\\\\(?:Domain|Application|Infrastructure)\\\\(?!Catalog\\\\|Shared\\\\)\w+\\\\/', (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[0] as $hit) {
                    $offences[] = substr($file->getPathname(), \strlen($root) + 1).': '.$hit;
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'The scan found no files: the path is wrong, so it would pass vacuously.');
        self::assertSame([], $offences);
    }
}
