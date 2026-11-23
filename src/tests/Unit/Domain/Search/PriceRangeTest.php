<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\Currency;
use App\Domain\Search\ValueObject\PriceRange;
use PHPUnit\Framework\TestCase;

/**
 * search-range-facets AC-4: a `PriceRange` holds a currency and an optional range in **minor units**;
 * whole units are scaled by `10^minorUnits`, an inverted pair is swapped.
 */
final class PriceRangeTest extends TestCase
{
    /**
     * @return array{string, ?int, ?int}
     */
    private function described(PriceRange $price): array
    {
        return [$price->currency()->value, $price->range()?->min(), $price->range()?->max()];
    }

    public function testWholeUnitsBecomeMinorUnits(): void
    {
        self::assertSame(['EUR', 10000, 50000], $this->described(PriceRange::inWholeUnits(Currency::EUR, 100, 500)));
    }

    public function testAnInvertedPairIsSwapped(): void
    {
        self::assertSame(['SEK', 10000, 50000], $this->described(PriceRange::inWholeUnits(Currency::SEK, 500, 100)));
    }

    public function testEachEndIsOptional(): void
    {
        self::assertSame(['USD', 0, null], $this->described(PriceRange::inWholeUnits(Currency::USD, 0, null)));
        self::assertSame(['USD', null, 9999999 * 100], $this->described(PriceRange::inWholeUnits(Currency::USD, null, 9999999)));
    }

    public function testACurrencyWithNoBoundHasNoRange(): void
    {
        $price = PriceRange::inWholeUnits(Currency::EUR, null, null);

        self::assertSame(Currency::EUR, $price->currency());
        self::assertNull($price->range());
    }
}
