<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\AttributeOptionId;

/**
 * No such option — or none *of this attribute*: an option reached through the wrong attribute is
 * the same fact to the caller, so it is the same exception (AC-21).
 */
final class AttributeOptionNotFound extends \DomainException
{
    public static function withId(AttributeOptionId $id): self
    {
        return new self(\sprintf('No attribute option found with id "%s".', $id->toString()));
    }

    /** For an id that is not even well-formed, e.g. a tampered route parameter. */
    public static function withMalformedId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No attribute option found with id "%s".', $id), 0, $previous);
    }
}
