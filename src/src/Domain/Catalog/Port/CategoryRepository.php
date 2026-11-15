<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Port;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\CorruptCategoryTree;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * The collection of Categories, plus the facts about the tree the aggregate needs handed to it.
 *
 * The tree reads (`lineageOf`, `subtreeHeightOf`, `hasChildren`, `isSlugTakenAmongSiblings`) are
 * only as true as the moment they were read: call them inside `CategoryTreeLock::exclusively()`,
 * together with the `save()` or `remove()` they justify.
 */
interface CategoryRepository
{
    /** Mints the identity for a Category that does not exist yet (UUIDv7 in the adapter). */
    public function nextIdentity(): CategoryId;

    /**
     * @throws CategoryNotFound
     */
    public function get(CategoryId $id): Category;

    /**
     * Root first, ending with `$id` itself. The walk is bounded by `Category::MAX_DEPTH + 1`
     * steps, so stored corruption is reported rather than looped over (C-4b).
     *
     * @throws CategoryNotFound
     * @throws CorruptCategoryTree
     */
    public function lineageOf(CategoryId $id): Lineage;

    /**
     * Levels in the subtree rooted at `$id`, counting `$id` itself (a leaf is 1). Bounded the same
     * way as `lineageOf()`.
     *
     * @return int<1, max>
     *
     * @throws CorruptCategoryTree
     */
    public function subtreeHeightOf(CategoryId $id): int;

    public function hasChildren(CategoryId $id): bool;

    /**
     * Whether a category other than `$except` already uses `$slug` under `$parentId` (null: among
     * the roots). The C-3 pre-check — it exists for the error message; the unique indexes are the
     * guarantee.
     */
    public function isSlugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug, ?CategoryId $except): bool;

    /**
     * Persists the aggregate, new or changed.
     *
     * Sibling-unique slugs (C-3) span instances, so the real guarantee is two partial unique
     * indexes; the adapter translates their violation into the domain exception (the I-6 pattern).
     *
     * @throws CategorySlugAlreadyTaken
     */
    public function save(Category $category): void;

    /**
     * Deletes the category. Whether it may be deleted (C-7) is the caller's check, under the lock.
     */
    public function remove(Category $category): void;
}
