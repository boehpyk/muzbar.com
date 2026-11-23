<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * A closed interval of whole numbers, either end optional — never empty and never inverted, by
 * construction (plan D3): `of()` returns `null` when neither end is given and swaps an inverted pair
 * (S-10), so no reader ever has to ask which end is which.
 *
 * Used for a range selection on a number facet and, in minor units, for a price filter. It is a
 * filter, not a rule: the attribute's `NumberRules` bounds play no part (S-12). There is no
 * `contains()` — SQL is the only evaluator.
 */
final readonly class NumberRange
{
    private function __construct(
        private ?int $min,
        private ?int $max,
    ) {
    }

    public static function of(?int $min, ?int $max): ?self
    {
        if (null === $min && null === $max) {
            return null;
        }

        if (null !== $min && null !== $max && $min > $max) {
            return new self($max, $min);
        }

        return new self($min, $max);
    }

    public function min(): ?int
    {
        return $this->min;
    }

    public function max(): ?int
    {
        return $this->max;
    }
}
