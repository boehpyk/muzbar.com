<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Catalog's view of what is filed under a category (ADR-0019, plan D3) — stated in Catalog's own
 * words, so neither Catalog's Domain nor its Application layer imports anything from `Listing`
 * (AC-8). The adapter lives with the data's owner, in `Infrastructure/Listing`.
 *
 * It backs C-23 (a listed-on category cannot be deleted) and C-24 (a listed-on category cannot gain
 * a child), the Catalog half of "every listing is filed under a leaf" (L-2 is Listing's half).
 */
interface CategoryUsage
{
    /**
     * Whether any listing, of any status, is filed under `$id`. False for an unknown id.
     *
     * Only true inside `CategoryTreeLock::exclusively()`: Listing writes hold the same lock in
     * shared mode, so none can file a listing between this read and the caller's commit.
     */
    public function hasListings(CategoryId $id): bool;

    /**
     * Whether any listing filed under any of `$ids` holds any attribute value (C-27, ADR-0020
     * decision 6). False for an empty list. Same lock contract as `hasListings()`.
     *
     * @param list<CategoryId> $ids
     */
    public function hasAttributeValues(array $ids): bool;
}
