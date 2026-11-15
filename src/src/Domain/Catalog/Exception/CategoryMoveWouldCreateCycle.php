<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

/**
 * C-4: the new parent is the category itself or one of its descendants.
 *
 * A user error, not a fault — never logged at `error` (AC-33). A cycle already *stored* is a
 * different thing entirely: see `CorruptCategoryTree`.
 */
final class CategoryMoveWouldCreateCycle extends \DomainException
{
    public static function for(CategoryId $id): self
    {
        return new self(\sprintf('Category "%s" cannot be moved inside itself or one of its descendants.', $id->toString()));
    }
}
