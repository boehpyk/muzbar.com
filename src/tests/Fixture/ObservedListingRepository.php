<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\ListingId;

/**
 * Delegates to the real repository and logs each call, with whether `holdSteady()` was active, in
 * the same ordered log as `ObservedCategories` (AC-18).
 */
final readonly class ObservedListingRepository implements ListingRepository
{
    public function __construct(
        private ListingRepository $inner,
        private ObservedCategories $categories,
    ) {
    }

    public function nextIdentity(): ListingId
    {
        $this->categories->record(__FUNCTION__);

        return $this->inner->nextIdentity();
    }

    public function get(ListingId $id): Listing
    {
        $this->categories->record(__FUNCTION__);

        return $this->inner->get($id);
    }

    public function save(Listing $listing): void
    {
        $this->categories->record(__FUNCTION__);

        $this->inner->save($listing);
    }
}
