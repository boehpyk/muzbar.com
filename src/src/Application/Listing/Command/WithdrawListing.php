<?php

declare(strict_types=1);

namespace App\Application\Listing\Command;

/**
 * The intent "take this listing down". `$sellerId` is who is asking (from the authenticated user),
 * checked against the listing's seller (L-6).
 */
final readonly class WithdrawListing
{
    public function __construct(
        public string $listingId,
        public string $sellerId,
    ) {
    }
}
