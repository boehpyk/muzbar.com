<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\CategoryStatus;

/**
 * Listing's view of the catalog, in Listing's own words (ADR-0019 decision 3): "does this category
 * exist, and is it a leaf?" and "hold the catalog steady while I file this".
 *
 * It lives in the Domain for ADR-0016 decision 3's reason: L-2's correctness depends on the status
 * staying true until commit. An admin adding a subcategory under a leaf while a seller files a
 * listing under it is write skew — each side reads a fact the other is about to break. So every
 * Listing write reads its status and saves inside `holdSteady()`, uniformly, so nobody decides per
 * handler whether "this one is safe". How the catalog is held (the Catalog tree lock in shared
 * mode) is the adapter's business; the adapter lives with the catalog's data (decision 4).
 */
interface Categories
{
    /**
     * Runs `$work` in one transaction during which no category write can commit; Listing writes do
     * not block each other. All of it commits, or none of it does. Whatever `$work` throws is
     * rethrown after the rollback.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function holdSteady(\Closure $work): mixed;

    /**
     * Whether `$id` names a category, and whether it has subcategories. **Only true inside
     * `holdSteady()`**: read outside it, it is a guess.
     */
    public function statusOf(CategoryId $id): CategoryStatus;

    /**
     * Every attribute in force on `$id` — its own and every ancestor's (ADR-0018 C-22) — with each
     * choice's option ids and each number's bounds; no labels (ADR-0020 decision 3). An unknown
     * category has `AttributeSchema::none()`. **Only true inside `holdSteady()`**: read outside it,
     * an admin can define, delete or narrow an attribute before the listing write commits.
     */
    public function schemaOf(CategoryId $id): AttributeSchema;
}
