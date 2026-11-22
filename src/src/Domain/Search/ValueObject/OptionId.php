<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

use App\Domain\Search\Exception\InvalidOptionId;
use App\Domain\Shared\ValueObject\UuidIdShape;

/**
 * Search's name for an attribute option, the value of a choice bucket — a `Catalog` option id. A local copy: never import another context's id
 * (ADR-0019 decision 2, ADR-0023 fence point 4); the adapter translates through `toString()`.
 *
 * The shape (format, no `generate()`, why a trait) is documented once, on {@see UuidIdShape}.
 */
final readonly class OptionId
{
    use UuidIdShape;

    private static function malformed(string $value): InvalidOptionId
    {
        return InvalidOptionId::malformed($value);
    }
}
