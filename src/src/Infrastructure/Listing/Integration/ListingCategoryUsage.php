<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Integration;

use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use Doctrine\DBAL\Connection;

/**
 * Catalog's view of what is filed under a category (ADR-0019, plan D3/D4) — one of the only two
 * classes that know both contexts. It lives with the data's owner: `listing_listing` is read here,
 * in Listing's Infrastructure, and nowhere under `Infrastructure/Catalog` (AC-7).
 *
 * Runs on the EntityManager's own connection, so inside `CategoryTreeLock::exclusively()` it reads
 * in the lock's transaction. Served by `idx_listing_listing_category`. Any status counts.
 */
final readonly class ListingCategoryUsage implements CategoryUsage
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function hasListings(CategoryId $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM listing_listing WHERE category_id = :id)',
            ['id' => $id->toString()],
        );
    }
}
