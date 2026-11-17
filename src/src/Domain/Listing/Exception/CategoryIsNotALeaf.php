<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\CategoryId;

/** L-2: a listing is filed under a leaf — a category with no subcategories. */
final class CategoryIsNotALeaf extends \DomainException
{
    public static function for(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" has subcategories; a listing is filed under a leaf.', $id->toString()));
    }
}
