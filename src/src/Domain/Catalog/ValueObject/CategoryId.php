<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of a Category, as a value — and the only thing any other aggregate, in any later
 * slice, may hold to refer to one (C-8). Never a slug, never a path: both are editable.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class CategoryId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidCategoryId
    {
        return InvalidCategoryId::malformed($value);
    }
}
