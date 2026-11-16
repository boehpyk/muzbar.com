<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\CorruptCategoryTree;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\CategoryIdType;
use App\Infrastructure\Catalog\Persistence\Doctrine\Type\CategorySlugType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The tree reads are plain DBAL on the EntityManager's own connection, so inside
 * `CategoryTreeLock::exclusively()` they run in the lock's transaction and see exactly the tree the
 * lock is protecting. Each recursive walk is bounded in SQL by `Category::MAX_DEPTH + 1` steps, so a
 * cycle planted in stored data is reported as `CorruptCategoryTree` rather than looped over (C-4b).
 */
final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    /** One step past the deepest legal chain: reaching it can only mean corruption. */
    private const int WALK_BOUND = Category::MAX_DEPTH + 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function nextIdentity(): CategoryId
    {
        return CategoryId::fromString(Uuid::v7()->toRfc4122());
    }

    public function get(CategoryId $id): Category
    {
        return $this->entityManager->find(Category::class, $id) ?? throw CategoryNotFound::withId($id);
    }

    public function lineageOf(CategoryId $id): Lineage
    {
        /** @var list<array{id: string, parent_id: ?string}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'WITH RECURSIVE up (id, parent_id, n) AS (
                SELECT id, parent_id, 1 FROM catalog_category WHERE id = :id
                UNION ALL
                SELECT c.id, c.parent_id, up.n + 1 FROM catalog_category c JOIN up ON c.id = up.parent_id
                WHERE up.n <= :bound
            )
            SELECT id, parent_id FROM up ORDER BY n DESC',
            ['id' => $id, 'bound' => self::WALK_BOUND],
            ['id' => CategoryIdType::NAME, 'bound' => ParameterType::INTEGER],
        );

        if ([] === $rows) {
            throw CategoryNotFound::withId($id);
        }

        // A walk that ran past the bound, or stopped on a row that still has a parent, never reached
        // a root: a cycle, or a chain of more than WALK_BOUND rows. A stored chain of exactly
        // WALK_BOUND rows is not reported here; anything placed under it is still refused by C-5.
        if (\count($rows) > self::WALK_BOUND || null !== $rows[0]['parent_id']) {
            throw CorruptCategoryTree::at($id, self::WALK_BOUND);
        }

        return Lineage::fromRootDown(array_map(
            static fn (array $row): CategoryId => CategoryId::fromString($row['id']),
            $rows,
        ));
    }

    public function subtreeHeightOf(CategoryId $id): int
    {
        $height = $this->connection()->fetchOne(
            'WITH RECURSIVE down (id, n) AS (
                SELECT id, 1 FROM catalog_category WHERE id = :id
                UNION ALL
                SELECT c.id, down.n + 1 FROM catalog_category c JOIN down ON c.parent_id = down.id
                WHERE down.n <= :bound
            )
            SELECT max(n) FROM down',
            ['id' => $id, 'bound' => self::WALK_BOUND],
            ['id' => CategoryIdType::NAME, 'bound' => ParameterType::INTEGER],
        );

        if (null === $height) {
            throw CategoryNotFound::withId($id);
        }

        // pdo_pgsql returns a PostgreSQL integer as a PHP int.
        if (!\is_int($height) || $height < 1 || $height > self::WALK_BOUND) {
            throw CorruptCategoryTree::at($id, self::WALK_BOUND);
        }

        return $height;
    }

    public function descendantsOf(CategoryId $id): array
    {
        /** @var list<array{id: string, n: int}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'WITH RECURSIVE down (id, n) AS (
                SELECT id, 1 FROM catalog_category WHERE id = :id
                UNION ALL
                SELECT c.id, down.n + 1 FROM catalog_category c JOIN down ON c.parent_id = down.id
                WHERE down.n <= :bound
            )
            SELECT id, n FROM down WHERE n > 1',
            ['id' => $id, 'bound' => self::WALK_BOUND],
            ['id' => CategoryIdType::NAME, 'bound' => ParameterType::INTEGER],
        );

        $descendants = [];

        foreach ($rows as $row) {
            // The same detection as `subtreeHeightOf()`: a level past the bound can only be a cycle.
            if ($row['n'] > self::WALK_BOUND) {
                throw CorruptCategoryTree::at($id, self::WALK_BOUND);
            }

            $descendants[] = CategoryId::fromString($row['id']);
        }

        return $descendants;
    }

    public function hasChildren(CategoryId $id): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM catalog_category WHERE parent_id = :id)',
            ['id' => $id],
            ['id' => CategoryIdType::NAME],
        );
    }

    public function isSlugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug, ?CategoryId $except): bool
    {
        // `IS NOT DISTINCT FROM` makes NULL match NULL, so the roots are one sibling group, as the
        // root-slug partial index has it. The casts are needed because PostgreSQL cannot infer a
        // type for a parameter it only ever sees compared with NULL.
        return (bool) $this->connection()->fetchOne(
            'SELECT EXISTS (
                SELECT 1 FROM catalog_category
                WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid)
                  AND slug = :slug
                  AND (CAST(:except AS uuid) IS NULL OR id <> CAST(:except AS uuid))
            )',
            ['parent' => $parentId, 'slug' => $slug, 'except' => $except],
            ['parent' => CategoryIdType::NAME, 'slug' => CategorySlugType::NAME, 'except' => CategoryIdType::NAME],
        );
    }

    public function save(Category $category): void
    {
        $this->entityManager->persist($category);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The two partial slug indexes are the only unique constraints besides the primary key,
            // and a UUIDv7 collision is not a case worth a branch. The EntityManager is closed now
            // (Doctrine cannot tell which pending changes survived): callers re-render from the DBAL
            // read model, never through the EM (plan Risk 2).
            throw CategorySlugAlreadyTaken::among($category->slug(), $e);
        }
    }

    public function remove(Category $category): void
    {
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
