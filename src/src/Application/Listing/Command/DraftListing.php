<?php

declare(strict_types=1);

namespace App\Application\Listing\Command;

/**
 * The intent "file this new listing, as a draft, under `$categoryId`". Strings only: everything is
 * checked by the Domain. `$sellerId` comes from the authenticated user, never from the request
 * (ADR-0019 decision 8). `$price` is the decimal text the seller typed ("1234,50").
 */
final readonly class DraftListing
{
    public function __construct(
        public string $sellerId,
        public string $categoryId,
        public string $title,
        public string $description,
        public string $price,
        public string $currency,
    ) {
    }
}
