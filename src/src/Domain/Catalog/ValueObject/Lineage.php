<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidLineage;

/**
 * The ids from a root category down to, and including, one category — root first.
 *
 * The lineage of a root is `[root]`. The **empty** lineage (`ofRoot()`) stands for "no parent":
 * it is what a category placed at root level is placed *under*. So "the new parent's lineage" is
 * always a `Lineage`, with no nullable special case for the root.
 *
 * It is a value object because it is a *fact handed to the aggregate* (plan D2): the handler reads
 * it through `CategoryRepository::lineageOf()` and `Category` decides C-4 and C-5 on it. It is
 * compared by value and never persisted — depth and ancestry are derived, never stored (plan D4).
 *
 * **A lineage is only true while the tree lock is held.** Nothing in this object can know whether
 * another write moved one of these categories after it was read; `CategoryTreeLock` is what keeps
 * the tree still between the read and the save. A lineage read outside `exclusively()` is a guess.
 */
final readonly class Lineage
{
    /**
     * @param list<CategoryId> $ids root first
     */
    private function __construct(
        private array $ids,
    ) {
    }

    /** No parent: a placement at root level. Depth 0. */
    public static function ofRoot(): self
    {
        return new self([]);
    }

    /**
     * @param list<CategoryId> $ids root first, ending with the category itself
     */
    public static function fromRootDown(array $ids): self
    {
        if ([] === $ids) {
            throw InvalidLineage::empty();
        }

        $seen = [];

        foreach ($ids as $id) {
            // A repeated id means the walk went round a loop — a cycle in the stored tree.
            if (isset($seen[$id->toString()])) {
                throw InvalidLineage::duplicate($id);
            }

            $seen[$id->toString()] = true;
        }

        return new self($ids);
    }

    /** The number of categories in the lineage: 1 for a root, 0 for "no parent". */
    public function depth(): int
    {
        return \count($this->ids);
    }

    /** The category this lineage leads to, or null for "no parent". */
    public function last(): ?CategoryId
    {
        return [] === $this->ids ? null : $this->ids[array_key_last($this->ids)];
    }

    public function contains(CategoryId $id): bool
    {
        foreach ($this->ids as $candidate) {
            if ($candidate->equals($id)) {
                return true;
            }
        }

        return false;
    }
}
