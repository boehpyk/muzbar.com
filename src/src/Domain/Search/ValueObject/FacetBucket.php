<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One checkbox of a facet: `value` is what goes back into `f[<attribute>][]` — an option id for a
 * choice, `yes`/`no` for a boolean — and `count` is disjunctive (S-7).
 */
final readonly class FacetBucket
{
    public function __construct(
        private string $value,
        private string $label,
        private int $count,
        private bool $selected,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function selected(): bool
    {
        return $this->selected;
    }
}
