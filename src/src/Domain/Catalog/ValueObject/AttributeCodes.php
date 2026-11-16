<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

/**
 * A sorted, de-duplicated, immutable set of attribute codes — the codes in force somewhere in the
 * tree, handed to an aggregate so it can decide C-21 ("an attribute code appears at most once along
 * any branch", ADR-0018).
 *
 * It is a value object because it is a *fact handed to the aggregate*, like `Lineage`: the handler
 * reads it through `AttributeRepository::codesDefinedOn()`, `Attribute` decides on it at define and
 * recode, `Category` at a move. It is compared by value and never persisted (C-22).
 *
 * **A set of codes is only true while the tree lock is held.** Nothing in this object can know
 * whether another write defined, recoded or moved something after it was read; `CategoryTreeLock`
 * is what keeps the catalog still between the read and the save. Codes read outside `exclusively()`
 * are a guess.
 *
 * It de-duplicates on purpose: a subtree may lawfully hold one code in two sibling branches, and
 * data written before C-21 existed may hold an ancestor/descendant duplicate (ADR-0018 decision 7).
 * Either way the question asked of it is only ever "is this code in force here", so a repeat adds
 * nothing.
 */
final readonly class AttributeCodes
{
    /**
     * @param list<AttributeCode> $codes sorted by `toString()`, no duplicates
     */
    private function __construct(
        private array $codes,
    ) {
    }

    public static function of(AttributeCode ...$codes): self
    {
        $byValue = [];

        foreach ($codes as $code) {
            $byValue[$code->toString()] = $code;
        }

        ksort($byValue, \SORT_STRING);

        return new self(array_values($byValue));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function contains(AttributeCode $code): bool
    {
        foreach ($this->codes as $candidate) {
            if ($candidate->equals($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The alphabetically first code present in both sets, or null when they are disjoint. "First"
     * makes the refusal message deterministic rather than dependent on read order.
     */
    public function firstSharedWith(self $other): ?AttributeCode
    {
        foreach ($this->codes as $code) {
            if ($other->contains($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return list<AttributeCode> sorted by `toString()`
     */
    public function toList(): array
    {
        return $this->codes;
    }
}
