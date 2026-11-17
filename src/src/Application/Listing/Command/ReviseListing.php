<?php

declare(strict_types=1);

namespace App\Application\Listing\Command;

/**
 * The intent "this listing is now these values". One submit of the edit form — every field is
 * sent, and the handler applies all of them or none. `$sellerId` is who is asking (from the
 * authenticated user), checked against the listing's seller (L-6).
 */
final readonly class ReviseListing
{
    public function __construct(
        public string $listingId,
        public string $sellerId,
        public string $categoryId,
        public string $title,
        public string $description,
        public string $price,
        public string $currency,
    ) {
    }
}
