<?php

declare(strict_types=1);

namespace App\Application\Catalog\Handler;

use App\Application\Catalog\Command\CreateCategory;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryHasListings;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\Port\CategoryTreeLock;
use App\Domain\Catalog\Port\CategoryUsage;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * Adds a category to the tree.
 *
 * Everything — the reads the aggregate decides on and the save — happens inside one
 * `exclusively()`. The value objects would throw on bad input before the lock is even needed, but
 * they stay inside it so that every Catalog write has one code shape and nobody has to reason
 * about which steps are safe outside.
 */
final readonly class CreateCategoryHandler
{
    public function __construct(
        private CategoryRepository $categories,
        private CategoryUsage $usage,
        private CategoryTreeLock $lock,
    ) {
    }

    /**
     * @throws \App\Domain\Catalog\Exception\InvalidCategoryName
     * @throws \App\Domain\Catalog\Exception\InvalidCategorySlug
     * @throws CategoryNotFound                                  the parent does not exist (C-6)
     * @throws CategoryHasListings                               the parent has listings (C-24)
     * @throws CategorySlugAlreadyTaken                          (C-3)
     * @throws \App\Domain\Catalog\Exception\CategoryTreeTooDeep (C-5)
     */
    public function __invoke(CreateCategory $command): CategoryId
    {
        return $this->lock->exclusively(function () use ($command): CategoryId {
            $name = CategoryName::fromString($command->name);
            $slug = CategorySlug::fromString($command->slug);

            // C-6: `lineageOf()` throws `CategoryNotFound` for a parent that is not there.
            $parentLineage = null === $command->parentId
                ? Lineage::ofRoot()
                : $this->categories->lineageOf($this->parentId($command->parentId));

            // C-24 (ADR-0019): a listed-on category cannot gain a child, or its listings would sit
            // on a branch. After C-6 (the parent exists), before C-3. A root reads no listings.
            $parentId = $parentLineage->last();

            if (null !== $parentId && $this->usage->hasListings($parentId)) {
                throw CategoryHasListings::cannotGainChild($parentId);
            }

            // C-3 pre-check, for the error message. Under the tree lock no other category write can
            // slip in between it and the save, but the partial unique indexes stay the real
            // guarantee against anything that bypasses the lock (the I-6 pattern).
            if ($this->categories->isSlugTakenAmongSiblings($parentLineage->last(), $slug, null)) {
                throw CategorySlugAlreadyTaken::among($slug);
            }

            $category = Category::create($this->categories->nextIdentity(), $name, $slug, $parentLineage);
            $this->categories->save($category);

            return $category->id();
        });
    }

    /**
     * A malformed parent id names a category that does not exist, which is all the caller needs
     * to know (AC-14) — a tampered form field gets the same answer as a deleted parent.
     */
    private function parentId(string $value): CategoryId
    {
        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw CategoryNotFound::withMalformedId($value, $e);
        }
    }
}
