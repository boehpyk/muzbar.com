<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle;
use App\Domain\Catalog\Exception\CategoryTreeTooDeep;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Domain\Catalog\ValueObject\CategorySlug;
use App\Domain\Catalog\ValueObject\Lineage;

/**
 * One node of the instrument taxonomy — and one aggregate per node (plan D1, ADR-0016).
 *
 * A category knows its parent **by id** and nothing about its children. Every later reference
 * (an attribute, a listing, a saved search) points at one category, which is why each category is
 * a root rather than an entity inside a `CategoryTree`.
 *
 * THE CONTRACT FOR THE RULES THAT SPAN THE TREE (plan D2). No cycles (C-4) and the depth ceiling
 * (C-5) involve other categories, which this object cannot see. So it is handed the facts it needs
 * — the new parent's `Lineage` and its own subtree height — and **it decides**, as pure logic a
 * unit test reaches without a database. What it cannot do is verify that those facts are current:
 * a lineage read a moment ago may already be wrong if another write moved something in between.
 * `CategoryTreeLock` is what makes them current — every write reads its facts and saves while
 * holding the one tree lock. That split is a design choice, not an oversight: the aggregate owns
 * the rule, the lock owns the truth of its inputs.
 *
 * Sibling-unique slugs (C-3), "the parent exists" (C-6) and "only a leaf may be deleted" (C-7) are
 * handler checks under the same lock; C-3 also has a unique index as its real guarantee. There is
 * no `delete()`: an aggregate governs its transitions, not its own non-existence (ADR-0012, D7).
 *
 * No domain events (plan D6): nothing consumes one yet, and the first consumer will need a payload
 * nobody can design today.
 *
 * Properties are plain `private`, not `readonly` (ADR-0007): Doctrine hydrates them.
 */
final class Category
{
    /**
     * The depth ceiling (C-5): a root has depth 1 and nothing is deeper than this. It also bounds
     * every recursive read of the tree (C-4b), so adapters quote this constant, never a literal.
     */
    public const int MAX_DEPTH = 4;

    private function __construct(
        private CategoryId $id,
        private ?CategoryId $parentId,
        private CategorySlug $slug,
        private CategoryName $name,
    ) {
    }

    /**
     * Takes the parent's lineage rather than a `?CategoryId`, so a category cannot be built under a
     * parent whose depth the caller did not look up.
     *
     * @throws CategoryTreeTooDeep
     */
    public static function create(CategoryId $id, CategoryName $name, CategorySlug $slug, Lineage $parentLineage): self
    {
        if ($parentLineage->depth() + 1 > self::MAX_DEPTH) {
            throw CategoryTreeTooDeep::create(self::MAX_DEPTH);
        }

        return new self($id, $parentLineage->last(), $slug, $name);
    }

    /**
     * Re-parents this category, taking its whole subtree along. Only this aggregate changes: the
     * descendants still point at it by id.
     *
     * Every check happens before any assignment, so a refused move mutates nothing.
     *
     * @param int $subtreeHeight levels in this category's subtree, counting itself (a leaf is 1)
     *
     * @throws CategoryMoveWouldCreateCycle
     * @throws CategoryTreeTooDeep
     */
    public function moveUnder(Lineage $newParentLineage, int $subtreeHeight): void
    {
        if ($subtreeHeight < 1) {
            throw new \InvalidArgumentException(\sprintf('A subtree height counts the category itself, so it is at least 1; got %d.', $subtreeHeight));
        }

        $newParentId = $newParentLineage->last();

        if ($this->isCurrentParent($newParentId)) {
            return;
        }

        // C-4 before C-5: moving a category under its own descendant is reported as the cycle it
        // is, never as a depth error that happens to be true as well.
        if ($newParentLineage->contains($this->id)) {
            throw CategoryMoveWouldCreateCycle::for($this->id);
        }

        if ($newParentLineage->depth() + $subtreeHeight > self::MAX_DEPTH) {
            throw CategoryTreeTooDeep::move(self::MAX_DEPTH);
        }

        $this->parentId = $newParentId;
    }

    public function rename(CategoryName $name): void
    {
        $this->name = $name;
    }

    public function changeSlug(CategorySlug $slug): void
    {
        $this->slug = $slug;
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function slug(): CategorySlug
    {
        return $this->slug;
    }

    public function name(): CategoryName
    {
        return $this->name;
    }

    private function isCurrentParent(?CategoryId $candidate): bool
    {
        if (null === $candidate || null === $this->parentId) {
            return $candidate === $this->parentId;
        }

        return $candidate->equals($this->parentId);
    }
}
