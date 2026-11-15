<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/** C-13: a category that defines attributes cannot be deleted — delete its attributes first. */
final class CategoryHasAttributes extends \DomainException
{
    public static function cannotDelete(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" defines attributes and cannot be deleted.', $id->toString()));
    }
}
