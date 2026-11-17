<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Integration;

use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;
use App\Infrastructure\Catalog\Persistence\Doctrine\PostgresCategoryTreeLock;
use Doctrine\DBAL\Connection;

/**
 * Listing's view of the catalog (ADR-0019, plan D3/D4) — one of the only two classes that know
 * both contexts. It lives with the data's owner: `catalog_category` is read here, in Catalog's
 * Infrastructure, and nowhere under `Infrastructure/Listing` (AC-7's data-ownership scan).
 *
 * `holdSteady()` takes the tree lock in shared mode, so `statusOf()` stays true until the listing
 * write commits: no category write (create under, move under, delete) can commit in between.
 */
final readonly class CategoriesForListing implements Categories
{
    public function __construct(
        private PostgresCategoryTreeLock $lock,
        private Connection $connection,
    ) {
    }

    public function holdSteady(\Closure $work): mixed
    {
        return $this->lock->sharedly($work);
    }

    public function statusOf(CategoryId $id): CategoryStatus
    {
        // No row: the category does not exist. Otherwise one row saying whether it has a child.
        // Served by the primary key and the leading `parent_id` of the sibling-slug index.
        $kind = $this->connection->fetchOne(
            "SELECT CASE WHEN EXISTS (SELECT 1 FROM catalog_category c WHERE c.parent_id = :id)
                         THEN 'branch' ELSE 'leaf' END
             FROM catalog_category WHERE id = :id",
            ['id' => $id->toString()],
        );

        return match ($kind) {
            false => CategoryStatus::Missing,
            'branch' => CategoryStatus::Branch,
            default => CategoryStatus::Leaf,
        };
    }
}
