<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Shared\Benchmark;

use App\Infrastructure\Shared\Benchmark\SeedSearchBenchmarkCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-benchmark AC-1 (the pure name check) and AC-2 (the seeder holds no SQL and names no table).
 */
final class SeedSearchBenchmarkCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function disposableNames(): iterable
    {
        yield 'bench' => ['muzbar_bench'];
        yield 'test' => ['muzbar_test'];
        yield 'paratest token' => ['muzbar_test3'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedNames(): iterable
    {
        yield 'production' => ['muzbar'];
        yield 'prod suffix' => ['muzbar_prod'];
        yield 'bench prefix' => ['bench_muzbar'];
        yield 'bench with a digit' => ['muzbar_bench2'];
        yield 'trailing newline' => ["muzbar_bench\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('disposableNames')]
    public function testADisposableDatabaseNameIsAccepted(string $name): void
    {
        self::assertTrue(SeedSearchBenchmarkCommand::isDisposable($name));
    }

    #[DataProvider('protectedNames')]
    public function testAnyOtherDatabaseNameIsRefused(string $name): void
    {
        self::assertFalse(SeedSearchBenchmarkCommand::isDisposable($name));
    }

    public function testTheSeederContainsNoSqlAndNamesNoTable(): void
    {
        $file = \dirname(__DIR__, 5).'/src/Infrastructure/Shared/Benchmark/SeedSearchBenchmarkCommand.php';
        $source = file_get_contents($file);
        self::assertIsString($source, 'The scan could not read the command: the path is wrong, so it would pass vacuously.');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }
        self::assertGreaterThan(1000, \strlen($code), 'Almost nothing was scanned.');

        preg_match_all('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|JOIN|TRUNCATE)\b/', $code, $keywords);
        preg_match_all('/\b(?:listing|catalog|search|identity|prelaunch|messenger)_[a-z_]+\b/', $code, $tables);

        self::assertSame([], $keywords[0], 'An SQL keyword appears in the seeder.');
        self::assertSame([], $tables[0], 'A table name appears in the seeder.');
    }
}
