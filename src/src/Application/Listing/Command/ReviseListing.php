<?php

declare(strict_types=1);

namespace App\Application\Listing\Command;

/**
 * The intent "this listing is now these values". One submit of the edit form — every field is
 * sent, and the handler applies all of them or none. `$sellerId` is who is asking (from the
 * authenticated user), checked against the listing's seller (L-6).
 *
 * `$attributes` is the "Details" section: attribute id → the seller's raw text, read against the
 * category's schema by the aggregate (L-9). Required, with no default: a revise replaces the whole
 * set, so `[]` clears every stored value — a caller must say so, never get it by omission.
 */
final readonly class ReviseListing
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public string $listingId,
        public string $sellerId,
        public string $categoryId,
        public string $title,
        public string $description,
        public string $price,
        public string $currency,
        public array $attributes,
    ) {
    }
}
