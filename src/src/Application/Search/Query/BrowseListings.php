<?php

declare(strict_types=1);

namespace App\Application\Search\Query;

/**
 * "Show me the published listings here, filtered like this, on this page." Every field is required
 * — the controller states each — and none is trusted: shapes are the controller's (plan D8), every
 * meaning the Domain's.
 */
final readonly class BrowseListings
{
    /**
     * @param ?string                                              $category a category id as typed in the URL, null for none
     * @param array<string, list<string>>                          $filters  `f[<attribute id>][]=<option id|yes|no>`
     * @param array<string, array{min: ?string, max: ?string}>     $ranges   `r[<attribute id>][min|max]=<whole number>`
     * @param array{currency: ?string, min: ?string, max: ?string} $price    `price[currency|min|max]`, bounds in whole units
     * @param ?string                                              $text     `q`, the search text as typed, null for none
     */
    public function __construct(
        public ?string $category,
        public array $filters,
        public array $ranges,
        public array $price,
        public ?string $text,
        public int $page,
    ) {
    }
}
