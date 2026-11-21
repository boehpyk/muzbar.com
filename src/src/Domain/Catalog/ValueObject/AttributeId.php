<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * The identity of an Attribute, as a value — what an option, and later a listing value, holds to
 * refer to one. Never the code: codes are editable (C-10/C-11).
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class AttributeId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidAttributeId
    {
        return InvalidAttributeId::malformed($value);
    }
}
