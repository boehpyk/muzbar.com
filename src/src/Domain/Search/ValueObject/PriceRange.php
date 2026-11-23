<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * A price filter (S-13, ADR-0024): a **currency**, and optionally a range in that currency's
 * **minor units** (the unit `listing_listing.price_amount` stores). Price is compared only within one
 * currency, so the currency is not optional: no conversion exists, and bounds without one mean
 * nothing (Q1b — `FilterRequest::parse()` drops them).
 *
 * A currency with no range is a filter too: "only listings priced in EUR".
 */
final readonly class PriceRange
{
    private function __construct(
        private Currency $currency,
        private ?NumberRange $range,
    ) {
    }

    /**
     * From bounds a visitor typed in **whole units** — the one place whole units become minor units
     * (`100` EUR → `10000`). An inverted pair is swapped by {@see NumberRange::of()}.
     */
    public static function inWholeUnits(Currency $currency, ?int $min, ?int $max): self
    {
        $scale = 10 ** $currency->minorUnits();

        return new self($currency, NumberRange::of(
            null === $min ? null : $min * $scale,
            null === $max ? null : $max * $scale,
        ));
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** Bounds in minor units; `null` when only the currency was chosen. */
    public function range(): ?NumberRange
    {
        return $this->range;
    }
}
