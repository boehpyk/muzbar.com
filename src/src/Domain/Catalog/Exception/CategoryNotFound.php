<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exception;

use App\Domain\Catalog\ValueObject\CategoryId;

final class CategoryNotFound extends \DomainException
{
    public static function withId(CategoryId $id): self
    {
        return new self(\sprintf('No category found with id "%s".', $id->toString()));
    }

    /**
     * For an id that is not even well-formed, e.g. a tampered parent field. To the caller it is the
     * same fact — that category does not exist — so it is the same exception (AC-14).
     */
    public static function withMalformedId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No category found with id "%s".', $id), 0, $previous);
    }
}
