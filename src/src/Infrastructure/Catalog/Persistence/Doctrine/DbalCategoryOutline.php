<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Entity\Category;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The whole category tree, flattened depth-first, for the admin screens (index and both parent
 * `<select>`s, AC-23) — one query per page.
 *
 * A read model with no port: only Infrastructure controllers consume it, and reads bypass the
 * Domain the way `ComingSoonController`'s do (CQRS-lite). It goes through DBAL, never the
 * EntityManager, so a form can still be re-rendered after a failed `flush()` has closed the EM
 * (plan Risk 2).
 *
 * Siblings are ordered by name, case-insensitively, with the id as tie-break (plan D12): each row
 * carries the path of `[lower(name), id]` pairs from its root, and ordering by that array is a
 * depth-first walk. The recursion stops at `Category::MAX_DEPTH`, so stored corruption cannot make
 * it loop; a cycle unreachable from any root simply does not appear.
 *
 * @phpstan-type OutlineRow array{id: string, parentId: ?string, name: string, slug: string, depth: int}
 */
final readonly class DbalCategoryOutline
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<OutlineRow>
     */
    public function rows(): array
    {
        /** @var list<array{id: string, parent_id: ?string, name: string, slug: string, depth: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'WITH RECURSIVE tree (id, parent_id, name, slug, depth, path) AS (
                SELECT id, parent_id, name, slug, 1, ARRAY[lower(name), id::text]
                FROM catalog_category WHERE parent_id IS NULL
                UNION ALL
                SELECT c.id, c.parent_id, c.name, c.slug, t.depth + 1, t.path || ARRAY[lower(c.name), c.id::text]
                FROM catalog_category c JOIN tree t ON c.parent_id = t.id
                WHERE t.depth < :max_depth
            )
            SELECT id, parent_id, name, slug, depth FROM tree ORDER BY path',
            ['max_depth' => Category::MAX_DEPTH],
            ['max_depth' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'parentId' => $row['parent_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'depth' => $row['depth'],
        ], $rows);
    }
}
