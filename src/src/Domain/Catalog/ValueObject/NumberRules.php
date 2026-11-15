<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidNumberRules;

/**
 * The unit and bounds of one number attribute. Every part is optional; when both bounds are given,
 * `minimum ≤ maximum` (C-16), and equal bounds are allowed.
 *
 * Whole numbers only (owner, Q1): a decimal number would be an additive rule later. There is no
 * `allows(int)` yet — nothing validates a value until the `Listing` context exists.
 */
final readonly class NumberRules
{
    private function __construct(
        private ?Unit $unit,
        private ?int $minimum,
        private ?int $maximum,
    ) {
    }

    /**
     * @throws InvalidNumberRules when the minimum is above the maximum
     */
    public static function of(?Unit $unit, ?int $minimum, ?int $maximum): self
    {
        if (null !== $minimum && null !== $maximum && $minimum > $maximum) {
            throw InvalidNumberRules::minimumAboveMaximum($minimum, $maximum);
        }

        return new self($unit, $minimum, $maximum);
    }

    public function unit(): ?Unit
    {
        return $this->unit;
    }

    public function minimum(): ?int
    {
        return $this->minimum;
    }

    public function maximum(): ?int
    {
        return $this->maximum;
    }
}
