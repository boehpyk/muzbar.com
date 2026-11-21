<?php

declare(strict_types=1);

namespace App\Domain\Listing\Exception;

use App\Domain\Listing\ValueObject\ListingId;

/**
 * L-15: a published listing keeps its category. Withdraw it, re-file, publish again — the edit form
 * was rendered for the old category, so the new one's required details could not have been filled.
 */
final class PublishedListingCannotBeRefiled extends \DomainException
{
    public static function for(ListingId $id): self
    {
        return new self(\sprintf('Listing "%s" is published; withdraw it before moving it to another category.', $id->toString()));
    }
}
