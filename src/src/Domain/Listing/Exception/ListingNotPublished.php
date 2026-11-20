<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingId;

/** L-12: only a published listing can be withdrawn — a draft was never public. */
final class ListingNotPublished extends \DomainException
{
    public static function cannotWithdraw(ListingId $id): self
    {
        return new self(\sprintf('Listing "%s" is not published, so it cannot be withdrawn.', $id->toString()));
    }
}
