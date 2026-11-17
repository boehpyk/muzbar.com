<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingId;

final class ListingNotFound extends \DomainException
{
    public static function withId(ListingId $id): self
    {
        return new self(\sprintf('No listing found with id "%s".', $id->toString()));
    }

    /** For an id that is not even well-formed (a tampered URL): the same fact, the same exception. */
    public static function withMalformedId(string $id, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No listing found with id "%s".', $id), 0, $previous);
    }
}
