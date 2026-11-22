<?php

declare(strict_types=1);

namespace App\Domain\Search\Exception;

/**
 * The browsed category does not exist — or its id is not even well-formed, which to a visitor is
 * the same fact (one 404, AC-21).
 */
final class UnknownCategory extends \DomainException
{
    public static function withId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No category found with id "%s".', $id), 0, $previous);
    }
}
