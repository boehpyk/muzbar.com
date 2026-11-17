<?php

declare(strict_types=1);

namespace App\Application\Listing\Handler;

use App\Application\Listing\Command\DraftListing;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\InvalidCategoryId;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;

/**
 * Drafts a new listing (AC-9, AC-10).
 *
 * Everything runs inside `holdSteady()`: the category status is only true while the catalog is held,
 * so the read and the save it justifies share one transaction under the shared lock (ADR-0019
 * decision 5). A repeated submit drafts a second listing — a user action, not a retry; the PRG
 * redirect is what prevents accidental resubmits.
 */
final readonly class DraftListingHandler
{
    public function __construct(
        private ListingRepository $listings,
        private Categories $categories,
    ) {
    }

    /**
     * @throws \App\Domain\Listing\Exception\InvalidSellerId
     * @throws \App\Domain\Listing\Exception\InvalidListingTitle       (L-3)
     * @throws \App\Domain\Listing\Exception\InvalidListingDescription (L-4)
     * @throws \App\Domain\Listing\Exception\InvalidPrice              (L-5, incl. an unsupported currency)
     * @throws UnknownCategory                                         (L-2, incl. a malformed id)
     * @throws \App\Domain\Listing\Exception\CategoryIsNotALeaf        (L-2)
     */
    public function __invoke(DraftListing $command): ListingId
    {
        return $this->categories->holdSteady(function () use ($command): ListingId {
            $categoryId = self::categoryId($command->categoryId);

            $listing = Listing::draft(
                $this->listings->nextIdentity(),
                SellerId::fromString($command->sellerId),
                $categoryId,
                $this->categories->statusOf($categoryId),
                ListingTitle::fromString($command->title),
                ListingDescription::fromString($command->description),
                Price::fromDecimal($command->price, Currency::fromCode($command->currency)),
            );

            $this->listings->save($listing);

            return $listing->id();
        });
    }

    /** A malformed category id is a category that does not exist (a tampered select). */
    private static function categoryId(string $value): CategoryId
    {
        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw UnknownCategory::withMalformedId($value, $e);
        }
    }
}
