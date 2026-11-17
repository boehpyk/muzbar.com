<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidPrice;

/**
 * The currencies a price may be in: a closed allow-list of ISO 4217 codes (owner, 2026-11-16;
 * ADR-0019 decision 7). Adding one is a code change and a test, never a migration.
 *
 * The cases are named by their ISO code rather than in TitleCase, on purpose: the code *is* the
 * name everybody uses, and the backing value is what `price_currency` stores (AC-2).
 */
enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case SEK = 'SEK';
    case UAH = 'UAH';

    /**
     * Strict: exact, upper-case codes only — the value comes from a select the form built from
     * `cases()`, so anything else is a tampered request, not a typo to forgive.
     *
     * @throws InvalidPrice
     */
    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? throw InvalidPrice::unsupportedCurrency($code);
    }

    /**
     * Digits after the decimal separator. All four allowed currencies have 2, but the number is
     * asked for rather than assumed, so a 0-decimal currency (JPY) is an explicit addition here and
     * not a silent ×100 bug in `Price`.
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::USD, self::EUR, self::SEK, self::UAH => 2,
        };
    }
}
