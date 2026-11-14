<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\EditCategory;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * Renames, re-slugs and moves a category in one all-or-nothing step (AC-16).
 *
 * Every step runs inside one `exclusively()`: a refusal anywhere rolls back the transaction, and
 * the lock adapter clears the EntityManager, so a rename applied in memory before a refused move
 * is forgotten rather than flushed by someone else later.
 */
final readonly class EditCategoryHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws InvalidCategoryId
     * @throws CategoryNotFound                                           the category or the new parent (C-6)
     * @throws \App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle (C-4)
     * @throws \App\Domain\Catalog\Exception\CategoryTreeTooDeep          (C-5)
     * @throws \App\Domain\Catalog\Exception\InvalidCategoryName
     * @throws \App\Domain\Catalog\Exception\InvalidCategorySlug
     * @throws CategorySlugAlreadyTaken                                   (C-3)
     */
    public function __invoke(EditCategory $command): void
    {
        $this->lock->exclusively(function () use ($command): void {
            $category = $this->categories->get(CategoryId::fromString($command->id));
            $oldParentId = $category->parentId();
            $oldSlug = $category->slug();

            // THE ORDER IS DELIBERATE: move, then rename, then re-slug, then the sibling check,
            // then save. The checks that consult the tree (C-4, C-5, C-6) run first, before the
            // cheap value checks, and the sibling pre-check runs last because it must look at the
            // *new* siblings under the *new* slug — both only known once the move and the re-slug
            // have been applied. A refusal at any step still costs nothing: the whole closure is
            // one transaction and the lock adapter forgets the half-mutated aggregate.
            $newParentLineage = null === $command->parentId
                ? Lineage::ofRoot()
                : $this->categories->lineageOf($this->parentId($command->parentId));
            $parentChanged = $newParentLineage->last()?->toString() !== $oldParentId?->toString();

            if ($parentChanged) {
                $category->moveUnder($newParentLineage, $this->categories->subtreeHeightOf($category->id()));
            }

            $name = CategoryName::fromString($command->name);

            if (!$name->equals($category->name())) {
                $category->rename($name);
            }

            $slug = CategorySlug::fromString($command->slug);
            $slugChanged = !$slug->equals($oldSlug);

            if ($slugChanged) {
                $category->changeSlug($slug);
            }

            // C-3 pre-check, for the error message; the partial unique indexes are the guarantee.
            // `except` is the category itself, which is not its own sibling.
            if (($slugChanged || $parentChanged)
                && $this->categories->isSlugTakenAmongSiblings($category->parentId(), $slug, $category->id())
            ) {
                throw CategorySlugAlreadyTaken::among($slug);
            }

            $this->categories->save($category);
        });
    }

    /** As in `CreateCategoryHandler`: a malformed parent id is a parent that does not exist (AC-14). */
    private function parentId(string $value): CategoryId
    {
        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw CategoryNotFound::withMalformedId($value, $e);
        }
    }
}
