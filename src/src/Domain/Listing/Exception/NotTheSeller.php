<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingId;

/**
 * L-6: only the seller may revise a listing. The HTTP layer answers it with the same 404 as
 * `ListingNotFound` — a 403 would confirm that a private draft exists (ADR-0019 decision 8).
 */
final class NotTheSeller extends \DomainException
{
    public static function of(ListingId $id): self
    {
        return new self(\sprintf('Only the seller may revise listing "%s".', $id->toString()));
    }
}
