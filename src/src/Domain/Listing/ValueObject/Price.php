<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidPrice;

/**
 * What a seller asks for the gear: a whole number of **minor units** (cents, kopiyky, öre) and a
 * `Currency` (L-5; ADR-0019 decisions 6 and 7). One value of two parts — "1234.50 EUR" is one
 * thing — so the aggregate holds one `Price`, mapped as an embeddable (ADR-0007, 2026-11-17).
 *
 * WHY MINOR UNITS AND STRING PARSING. Every arithmetic path here is on integers and strings; no
 * binary fraction is ever involved, because one would round money: through a double, 1.15 × 100
 * truncates to 114 and 0.29 × 100 to 28. The decimal text is split at its separator, the fraction
 * is right-padded to the currency's number of minor units, and the two halves are combined as
 * integers. The parser lives in the Domain because "how many decimals does this currency have"
 * is business knowledge, not a form concern.
 *
 * WHY THE COMMA. Ukrainian and Swedish sellers type "1234,50", so `,` is accepted as the decimal
 * separator alongside `.`. Thousands separators ("1 234,50") are refused: they are locale-dependent
 * and ambiguous ("1,234"), and the error message shows the accepted shape.
 *
 * Range: 1 … 999 999 999 minor units (9 999 999.99 at two decimals) — no free, no zero. Only ASCII
 * digits are accepted, at most 7 before the separator.
 *
 * NOT DEFINED: comparing or ordering prices **across currencies**. `equals()` compares amount and
 * currency; there is no `greaterThan()`. Phase 2 decides cross-currency facets and sorting
 * (ADR-0019, Consequences).
 */
final readonly class Price
{
    public const int MAX_MINOR_UNITS = 999_999_999;

    /** Digits before the separator: 7 is the most that keeps 2-decimal prices under the maximum. */
    private const int MAX_INTEGER_DIGITS = 7;

    private function __construct(
        private int $amountInMinorUnits,
        private Currency $currency,
    ) {
    }

    /**
     * @throws InvalidPrice
     */
    public static function fromMinorUnits(int $amountInMinorUnits, Currency $currency): self
    {
        if ($amountInMinorUnits < 1 || $amountInMinorUnits > self::MAX_MINOR_UNITS) {
            throw InvalidPrice::outOfRange($amountInMinorUnits);
        }

        return new self($amountInMinorUnits, $currency);
    }

    /**
     * Parses what a seller typed: "1234.5", "1234,50", " 7 ".
     *
     * @throws InvalidPrice
     */
    public static function fromDecimal(string $value, Currency $currency): self
    {
        $trimmed = trim($value);
        $minorUnits = $currency->minorUnits();

        // No `u` modifier: `[0-9]` and `\d` then match ASCII digits only, never "١٢". `D`: a bare
        // `$` would also match before a trailing "\n".
        $pattern = 0 === $minorUnits
            ? \sprintf('/^([0-9]{1,%d})$/D', self::MAX_INTEGER_DIGITS)
            : \sprintf('/^([0-9]{1,%d})(?:[.,]([0-9]{1,%d}))?$/D', self::MAX_INTEGER_DIGITS, $minorUnits);

        if (1 !== preg_match($pattern, $trimmed, $parts)) {
            throw InvalidPrice::malformed($value);
        }

        $whole = (int) $parts[1];
        $fraction = (int) str_pad($parts[2] ?? '', $minorUnits, '0');

        return self::fromMinorUnits($whole * 10 ** $minorUnits + $fraction, $currency);
    }

    public function amountInMinorUnits(): int
    {
        return $this->amountInMinorUnits;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** "1234.50" for 123450 EUR — always every minor digit, `.` as the separator. */
    public function toDecimalString(): string
    {
        $minorUnits = $this->currency->minorUnits();

        if (0 === $minorUnits) {
            return (string) $this->amountInMinorUnits;
        }

        $scale = 10 ** $minorUnits;

        return \sprintf(
            '%d.%s',
            intdiv($this->amountInMinorUnits, $scale),
            str_pad((string) ($this->amountInMinorUnits % $scale), $minorUnits, '0', \STR_PAD_LEFT),
        );
    }

    public function equals(self $other): bool
    {
        return $this->amountInMinorUnits === $other->amountInMinorUnits
            && $this->currency === $other->currency;
    }
}
