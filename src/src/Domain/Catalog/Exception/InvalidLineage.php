<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

final class InvalidLineage extends \DomainException
{
    public static function empty(): self
    {
        return new self('A lineage leads to a category, so it cannot be empty; use Lineage::ofRoot() for "no parent".');
    }

    public static function duplicate(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" appears twice in one lineage, which would be a cycle.', $id->toString()));
    }
}
