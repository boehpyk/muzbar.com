<?php

declare(strict_types=1);

namespace App\Domain\Search\Exception;

final class InvalidOptionId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid option id.', $value));
    }
}
