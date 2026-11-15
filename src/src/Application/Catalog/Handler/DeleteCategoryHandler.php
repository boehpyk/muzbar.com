<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\DeleteCategory;
use App\Domain\Catalog\Exception\CategoryHasChildren;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * Deletes a leaf (C-7).
 *
 * The precondition lives here, not on the aggregate: an aggregate governs its transitions, not its
 * own non-existence (ADR-0012, plan D7). It is checked under the tree lock, so a child cannot be
 * created under this category between the check and the delete — the race that would leave an
 * orphan. Later slices that make something reference a category add their own guard here.
 */
final readonly class DeleteCategoryHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws \App\Domain\Catalog\Exception\InvalidCategoryId
     * @throws \App\Domain\Catalog\Exception\CategoryNotFound
     * @throws CategoryHasChildren
     */
    public function __invoke(DeleteCategory $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $category = $this->categories->get(CategoryId::fromString($command->id));

            if ($this->categories->hasChildren($category->id())) {
                throw CategoryHasChildren::cannotDelete($category->id());
            }

            $this->categories->remove($category);
        });
    }
}
