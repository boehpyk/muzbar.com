<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\CategoryId;

/** L-2: the category a listing is filed under must exist. */
final class UnknownCategory extends \DomainException
{
    public static function for(CategoryId $id): self
    {
        return new self(\sprintf('No category found with id "%s".', $id->toString()));
    }

    /**
     * For an id that is not even well-formed, e.g. a tampered select. To the seller it is the same
     * fact — that category does not exist — so it is the same exception.
     */
    public static function withMalformedId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No category found with id "%s".', $id), 0, $previous);
    }
}
