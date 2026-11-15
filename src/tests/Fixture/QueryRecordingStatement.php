<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

/**
 * Records the SQL of every statement PDO creates while installed as `PDO::ATTR_STATEMENT_CLASS`.
 *
 * The test kernel has no profiler and no DBAL logging middleware, and adding either would mean
 * changing production config for a test's sake. PDO builds one statement object per `prepare()` or
 * `query()`, so counting constructions counts the SQL the application sent, on the very connection
 * (DAMA's shared one) the request used.
 */
final class QueryRecordingStatement extends \PDOStatement
{
    /** @var list<string> */
    private static array $queries = [];

    protected function __construct()
    {
        self::$queries[] = $this->queryString;
    }

    public static function install(\PDO $pdo): void
    {
        self::$queries = [];
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [self::class]);
    }

    /**
     * @return list<string> the SQL recorded since `install()`
     */
    public static function uninstall(\PDO $pdo): array
    {
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);

        return self::$queries;
    }
}
