<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * Delegates to the real repository and records, per call, whether the observed tree lock was held.
 */
final class ObservedCategoryRepository implements CategoryRepository
{
    /** @var list<array{string, bool}> */
    private array $calls = [];

    public function __construct(
        private readonly CategoryRepository $inner,
        private readonly ObservedCategoryTreeLock $lock,
    ) {
    }

    /**
     * @return list<array{string, bool}> method name and "lock held when called"
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private function seen(string $method): void
    {
        $this->calls[] = [$method, $this->lock->isHeld()];
    }

    public function nextIdentity(): CategoryId
    {
        $this->seen(__FUNCTION__);

        return $this->inner->nextIdentity();
    }

    public function get(CategoryId $id): Category
    {
        $this->seen(__FUNCTION__);

        return $this->inner->get($id);
    }

    public function lineageOf(CategoryId $id): Lineage
    {
        $this->seen(__FUNCTION__);

        return $this->inner->lineageOf($id);
    }

    public function subtreeHeightOf(CategoryId $id): int
    {
        $this->seen(__FUNCTION__);

        return $this->inner->subtreeHeightOf($id);
    }

    public function descendantsOf(CategoryId $id): array
    {
        $this->seen(__FUNCTION__);

        return $this->inner->descendantsOf($id);
    }

    public function hasChildren(CategoryId $id): bool
    {
        $this->seen(__FUNCTION__);

        return $this->inner->hasChildren($id);
    }

    public function isSlugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug, ?CategoryId $except): bool
    {
        $this->seen(__FUNCTION__);

        return $this->inner->isSlugTakenAmongSiblings($parentId, $slug, $except);
    }

    public function save(Category $category): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->save($category);
    }

    public function remove(Category $category): void
    {
        $this->seen(__FUNCTION__);
        $this->inner->remove($category);
    }
}
