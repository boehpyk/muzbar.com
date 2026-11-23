<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * The currencies a price filter may name (S-13, ADR-0024) — Search's **mirror** of Listing's
 * `Currency`, copied by value: Search's Domain may not import Listing (ADR-0023 fence point 4), yet
 * it needs the allow-list and the exponent to resolve a bound. The same move as `FacetKind` ↔
 * Catalog's `AttributeType`.
 *
 * Adding or removing a code happens in **both** enums. `CurrencyMirrorTest` (S-14) fails when the
 * cases or an exponent differ — the exponent is the drift that matters, because a wrong one is a
 * silent ×100 in every price bound.
 *
 * Cases are ISO codes, not TitleCase, as on Listing's enum: the code is the name.
 */
enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case SEK = 'SEK';

    /**
     * Digits after the decimal separator. Bounded so `10 ** minorUnits()` is provably an `int`.
     *
     * @return int<0, 4>
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::USD, self::EUR, self::SEK => 2,
        };
    }
}
