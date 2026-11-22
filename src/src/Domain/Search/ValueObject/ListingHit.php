<?php

declare(strict_types=1);

namespace App\Domain\Search\ValueObject;

/**
 * One published listing on a results page, already formatted for display: `price` is the decimal
 * string Listing's `Price` reads back (plan D7), `publishedAt` is `DATE_ATOM`.
 *
 * **There is no seller field to fill** (S-9): no Search read selects `seller_id`.
 */
final readonly class ListingHit
{
    public function __construct(
        private string $id,
        private string $title,
        private string $price,
        private string $currency,
        private string $publishedAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function price(): string
    {
        return $this->price;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function publishedAt(): string
    {
        return $this->publishedAt;
    }
}
