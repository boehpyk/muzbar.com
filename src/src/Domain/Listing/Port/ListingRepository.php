<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\ValueObject\ListingId;

/**
 * The collection of Listings. Call `get()` and `save()` inside `Categories::holdSteady()`, together
 * with the `statusOf()` read the write is decided on (ADR-0019 decision 5).
 */
interface ListingRepository
{
    /** Mints the identity for a Listing that does not exist yet (UUIDv7 in the adapter). */
    public function nextIdentity(): ListingId;

    /**
     * @throws ListingNotFound
     */
    public function get(ListingId $id): Listing;

    /** Persists the aggregate, new or changed. */
    public function save(Listing $listing): void;
}
