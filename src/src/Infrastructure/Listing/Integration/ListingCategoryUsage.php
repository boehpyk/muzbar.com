<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Integration;

use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Catalog's view of what is filed under a category (ADR-0019, plan D3/D4) — one of the two
 * cross-context *adapters* (plus one UI read: `SellController` reuses `DbalCategoryOutline`, plan D13). It lives with the data's owner: `listing_listing` is read here,
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

    /**
     * C-27: the subtree's listings joined to their values — `idx_listing_listing_category` finds the
     * listings, the values' primary key (leading `listing_id`) answers EXISTS.
     */
    public function hasAttributeValues(array $ids): bool
    {
        if ([] === $ids) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM listing_attribute_value v
                JOIN listing_listing l ON l.id = v.listing_id
                WHERE l.category_id IN (:ids))',
            ['ids' => array_map(static fn (CategoryId $id): string => $id->toString(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
