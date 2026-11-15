<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/** C-7: only a leaf may be deleted. */
final class CategoryHasChildren extends \DomainException
{
    public static function cannotDelete(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" has subcategories and cannot be deleted.', $id->toString()));
    }
}
