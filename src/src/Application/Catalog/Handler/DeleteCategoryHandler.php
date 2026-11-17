<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\DeleteCategory;
use App\Domain\Catalog\Exception\CategoryHasAttributes;
use App\Domain\Catalog\Exception\CategoryHasChildren;
use App\Domain\Catalog\Exception\CategoryHasListings;
use App\Domain\Catalog\Port\AttributeRepository;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Deletes a leaf (C-7).
 *
 * The precondition lives here, not on the aggregate: an aggregate governs its transitions, not its
 * own non-existence (ADR-0012, plan D7). It is checked under the tree lock, so a child cannot be
 * created under this category between the check and the delete — the race that would leave an
 * orphan. Later slices that make something reference a category add their own guard here.
 *
 * Guards accrete (ADR-0016, 2026-11-15 amendment): C-13, "a category that defines attributes cannot
 * be deleted", is the first — attributes reference a category by id with no FK, so forgetting it
 * would leave orphaned attributes silently. C-23, "a listed-on category cannot be deleted", is the
 * second (ADR-0019): listings reference a category by id with no FK too. The list is now C-7, C-13,
 * C-23, in that order; the next thing that hangs off a category adds its guard here, never a cascade.
 */
final readonly class DeleteCategoryHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private CategoryUsage $usage,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws \App\Domain\Catalog\Exception\InvalidCategoryId
     * @throws \App\Domain\Catalog\Exception\CategoryNotFound
     * @throws CategoryHasChildren
     * @throws CategoryHasAttributes
     * @throws CategoryHasListings
     */
    public function __invoke(DeleteCategory $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $category = $this->categories->get(CategoryId::fromString($command->id));

            if ($this->categories->hasChildren($category->id())) {
                throw CategoryHasChildren::cannotDelete($category->id());
            }

            // C-13 after C-7, deliberately: a category with children *and* attributes reports the
            // children (AC-24) — the structural reason comes first.
            if ($this->attributes->existsForCategory($category->id())) {
                throw CategoryHasAttributes::cannotDelete($category->id());
            }

            // C-23 last: C-7 → C-13 → C-23, structural reasons first (AC-13). Under the lock,
            // and Listing writes hold it shared, so no listing can be filed here before the delete.
            if ($this->usage->hasListings($category->id())) {
                throw CategoryHasListings::cannotDelete($category->id());
            }

            $this->categories->remove($category);
        });
    }
}
