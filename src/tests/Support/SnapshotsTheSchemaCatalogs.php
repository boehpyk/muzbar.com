<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * ADR-0004's "no runtime DDL" as an observable: the columns and indexes of schema `public`. PostgreSQL
 * DDL is transactional, so a stray `ALTER TABLE` / `CREATE INDEX` is visible inside DAMA's test
 * transaction. The using class must expose a `Connection` as `$this->db`.
 *
 * @property Connection $db
 */
trait SnapshotsTheSchemaCatalogs
{
    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function catalogs(): array
    {
        return [
            $this->db->fetchAllAssociative("SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, ordinal_position FROM information_schema.columns WHERE table_schema = 'public' ORDER BY table_name, column_name"),
            $this->db->fetchAllAssociative("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY tablename, indexname"),
        ];
    }
}
