<?php

declare(strict_types=1);

namespace App\Application\Listing\Command;

/**
 * The intent "file this new listing, as a draft, under `$categoryId`". Strings only: everything is
 * checked by the Domain. `$sellerId` comes from the authenticated user, never from the request
 * (ADR-0019 decision 8). `$price` is the decimal text the seller typed ("1234,50").
 *
 * `$attributes` is the "Details" section: attribute id → the seller's raw text, read against the
 * category's schema by the aggregate at birth (L-9). Required, with no default: the set is the
 * listing's whole set of values, so `[]` means "no details" — a caller must say so, never get it by
 * omission (`ReviseListing::$attributes`' reason).
 *
 * `$publish`: also publish, in the same transaction — either the draft and the publish both commit,
 * or neither does, so a refused publish leaves no row. No default, for `$attributes`' reason.
 */
final readonly class DraftListing
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public string $sellerId,
        public string $categoryId,
        public string $title,
        public string $description,
        public string $price,
        public string $currency,
        public array $attributes,
        public bool $publish,
    ) {
    }
}
