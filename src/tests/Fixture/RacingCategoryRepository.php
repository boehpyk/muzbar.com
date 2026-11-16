<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Port\CategoryRepository;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * Delegates to the real repository, running one callback just before the first `save()` or
 * `lineageOf()` call: a second admin's write landing between the handler's pre-check and its save.
 * The callback acts through raw DBAL, on the same connection, so the real unique indexes decide.
 */
final class RacingCategoryRepository implements CategoryRepository
{
    private bool $fired = false;

    /**
     * @param \Closure(): void $interloper
     */
    public function __construct(
        private readonly CategoryRepository $inner,
        private readonly \Closure $interloper,
        private readonly string $before,
    ) {
    }

    private function race(string $method): void
    {
        if ($method === $this->before && !$this->fired) {
            $this->fired = true;
            ($this->interloper)();
        }
    }

    public function nextIdentity(): CategoryId
    {
        return $this->inner->nextIdentity();
    }

    public function get(CategoryId $id): Category
    {
        return $this->inner->get($id);
    }

    public function lineageOf(CategoryId $id): Lineage
    {
        $this->race(__FUNCTION__);

        return $this->inner->lineageOf($id);
    }

    public function subtreeHeightOf(CategoryId $id): int
    {
        return $this->inner->subtreeHeightOf($id);
    }

    public function descendantsOf(CategoryId $id): array
    {
        return $this->inner->descendantsOf($id);
    }

    public function hasChildren(CategoryId $id): bool
    {
        return $this->inner->hasChildren($id);
    }

    public function isSlugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug, ?CategoryId $except): bool
    {
        return $this->inner->isSlugTakenAmongSiblings($parentId, $slug, $except);
    }

    public function save(Category $category): void
    {
        $this->race(__FUNCTION__);
        $this->inner->save($category);
    }

    public function remove(Category $category): void
    {
        $this->inner->remove($category);
    }
}
