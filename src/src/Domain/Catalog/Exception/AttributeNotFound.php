<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeId;

final class AttributeNotFound extends \DomainException
{
    public static function withId(AttributeId $id): self
    {
        return new self(\sprintf('No attribute found with id "%s".', $id->toString()));
    }

    /** For an id that is not even well-formed: to the caller it is the same fact, so the same exception. */
    public static function withMalformedId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No attribute found with id "%s".', $id), 0, $previous);
    }
}
