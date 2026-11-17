<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

final class InvalidSellerId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid seller id.', $value));
    }
}
