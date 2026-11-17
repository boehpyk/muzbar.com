<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\Price;

final class InvalidPrice extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a price: expected digits with an optional "." or "," and up to the currency\'s number of decimals.', $value));
    }

    public static function outOfRange(int $amountInMinorUnits): self
    {
        return new self(\sprintf('A price must be between 1 and %d minor units, got %d.', Price::MAX_MINOR_UNITS, $amountInMinorUnits));
    }

    public static function unsupportedCurrency(string $code): self
    {
        return new self(\sprintf('"%s" is not a supported currency.', $code));
    }
}
