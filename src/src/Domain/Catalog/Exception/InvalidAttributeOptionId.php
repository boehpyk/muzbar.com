<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

final class InvalidAttributeOptionId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid attribute option id.', $value));
    }
}
