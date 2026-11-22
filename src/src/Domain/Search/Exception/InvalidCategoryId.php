<?php

declare(strict_types=1);

namespace App\Domain\Search\Exception;

final class InvalidCategoryId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid category id.', $value));
    }
}
