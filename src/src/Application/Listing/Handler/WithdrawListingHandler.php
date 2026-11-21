<?php

declare(strict_types=1);

namespace App\Application\Listing\Handler;

use App\Application\Listing\Command\WithdrawListing;
use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\SellerId;

/**
 * Withdraws a published listing (L-12). Withdrawing an already-withdrawn one is the aggregate's
 * no-op, so a double-submit needs no branch here.
 *
 * Inside `holdSteady()` although it reads no catalog fact: the rule is "every Listing write", so
 * nobody decides per handler whether "this one is safe" (ADR-0019 decision 5, plan D7). No event:
 * `withdraw()` records none.
 */
final readonly class WithdrawListingHandler
{
    public function __construct(
        private ListingRepository $listings,
        private Categories $categories,
    ) {
    }

    /**
     * @throws ListingNotFound                                   (incl. a malformed id)
     * @throws \App\Domain\Listing\Exception\InvalidSellerId
     * @throws \App\Domain\Listing\Exception\NotTheSeller        (L-6)
     * @throws \App\Domain\Listing\Exception\ListingNotPublished (L-12)
     */
    public function __invoke(WithdrawListing $command): void
    {
        $this->categories->holdSteady(function () use ($command): void {
            $listing = $this->listings->get(self::listingId($command->listingId));
            $listing->withdraw(SellerId::fromString($command->sellerId));
            $this->listings->save($listing);
        });
    }

    /** A malformed listing id is a listing that does not exist (a tampered URL). */
    private static function listingId(string $value): ListingId
    {
        try {
            return ListingId::fromString($value);
        } catch (InvalidListingId $e) {
            throw ListingNotFound::withMalformedId($value, $e);
        }
    }
}
