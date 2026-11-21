<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeOptionId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of an AttributeOption — what a listing value (and later a dependent option) holds to
 * refer to one. It survives a relabel (C-19), which is the point of giving options their own id.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class AttributeOptionId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidAttributeOptionId
    {
        return InvalidAttributeOptionId::malformed($value);
    }
}
