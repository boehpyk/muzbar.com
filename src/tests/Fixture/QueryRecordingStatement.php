<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

/**
 * Records the SQL of every statement PDO creates while installed as `PDO::ATTR_STATEMENT_CLASS`,
 * and the values bound to it.
 *
 * The test kernel has no profiler and no DBAL logging middleware, and adding either would mean
 * changing production config for a test's sake. PDO builds one statement object per `prepare()` or
 * `query()`, so counting constructions counts the SQL the application sent, on the very connection
 * (DAMA's shared one) the request used.
 *
 * Bound values (search-browse plan D9, Risk 4): DBAL 4's PDO driver binds every parameter through
 * `PDOStatement::bindValue($position, $value, $pdoType)` — positions are 1-based and the SQL it
 * prepares already has `?` placeholders, with arrays expanded — so overriding `bindValue()` sees them
 * all (verified empirically, 2026-11-22). A caller passing an array to `PDOStatement::execute()`
 * instead would not be recorded — nothing in this codebase does.
 */
final class QueryRecordingStatement extends \PDOStatement
{
    /** @var list<array{sql: string, params: array<int, array{mixed, int}>}> */
    private static array $statements = [];

    private int $index;

    protected function __construct()
    {
        $this->index = \count(self::$statements);
        self::$statements[] = ['sql' => $this->queryString, 'params' => []];
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        self::$statements[$this->index]['params'][(int) $param] = [$value, $type];

        return parent::bindValue($param, $value, $type);
    }

    public static function install(\PDO $pdo): void
    {
        self::$statements = [];
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [self::class]);
    }

    /**
     * @return list<string> the SQL recorded since `install()`
     */
    public static function uninstall(\PDO $pdo): array
    {
        return array_column(self::uninstallWithValues($pdo), 'sql');
    }

    /**
     * @return list<array{sql: string, params: array<int, array{mixed, int}>}> each statement and what was bound to it,
     *                                                                         by 1-based position as `[value, PDO::PARAM_*]`
     */
    public static function uninstallWithValues(\PDO $pdo): array
    {
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);

        return self::$statements;
    }
}
