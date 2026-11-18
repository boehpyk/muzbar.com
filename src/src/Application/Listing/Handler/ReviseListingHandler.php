<?php

declare(strict_types=1);

namespace App\Application\Listing\Handler;

use App\Application\Listing\Command\ReviseListing;
use App\Domain\Listing\Exception\InvalidCategoryId;
use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Listing\Exception\ListingNotFound;
use App\Domain\Listing\Exception\UnknownCategory;
use App\Domain\Listing\Port\Categories;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingDescription;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\ListingTitle;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;

/**
 * Revises a listing in one all-or-nothing step (AC-11, AC-12).
 *
 * Inside `holdSteady()` like every Listing write — **including** a revise that keeps its category:
 * the rule is uniform so nobody decides per handler whether "this one is safe" (ADR-0019 decision
 * 5). The category status and its attribute schema are read unconditionally, even when the category
 * is unchanged and the aggregate will ignore the status: a handler with no branch. The schema is
 * needed either way (L-9 reads current rules on every revise), and read **inside** the lock it is
 * true until commit (ADR-0020 decision 3). L-6 and L-2 are the
 * aggregate's; a refusal rolls the transaction back and the lock adapter forgets the aggregate.
 *
 * The values are built before `revise()` is called, so a non-seller who also sends a bad value is
 * refused for the value. That leaks nothing over HTTP only because the controller looks the draft
 * up **filtered by seller** first and answers 404 before this handler runs.
 */
final readonly class ReviseListingHandler
{
    public function __construct(
        private ListingRepository $listings,
        private Categories $categories,
    ) {
    }

    /**
     * @throws ListingNotFound                                         (incl. a malformed id)
     * @throws \App\Domain\Listing\Exception\InvalidSellerId
     * @throws \App\Domain\Listing\Exception\NotTheSeller              (L-6)
     * @throws \App\Domain\Listing\Exception\InvalidListingTitle       (L-3)
     * @throws \App\Domain\Listing\Exception\InvalidListingDescription (L-4)
     * @throws \App\Domain\Listing\Exception\InvalidPrice              (L-5, incl. an unsupported currency)
     * @throws UnknownCategory                                         (L-2, incl. a malformed id)
     * @throws \App\Domain\Listing\Exception\CategoryIsNotALeaf        (L-2)
     * @throws \App\Domain\Listing\Exception\InvalidAttributeValue     (L-9, incl. a malformed key)
     */
    public function __invoke(ReviseListing $command): void
    {
        $this->categories->holdSteady(function () use ($command): void {
            $listing = $this->listings->get(self::listingId($command->listingId));
            $categoryId = self::categoryId($command->categoryId);

            $listing->revise(
                SellerId::fromString($command->sellerId),
                $categoryId,
                $this->categories->statusOf($categoryId),
                $this->categories->schemaOf($categoryId),
                ListingTitle::fromString($command->title),
                ListingDescription::fromString($command->description),
                Price::fromDecimal($command->price, Currency::fromCode($command->currency)),
                AttributeInput::fromArray($command->attributes),
            );

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
