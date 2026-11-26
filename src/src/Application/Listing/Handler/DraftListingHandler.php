<?php

declare(strict_types=1);

namespace App\Application\Listing\Handler;

use App\Application\Listing\Command\DraftListing;
use App\Domain\Listing\Entity\Listing;
use App\Domain\Listing\Exception\InvalidCategoryId;
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
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Drafts a new listing with its details — and, when asked, publishes it — in one all-or-nothing
 * step (AC-9, AC-10, AC-12, AC-13). A refused publish rolls the draft back with it: no row is left.
 *
 * Everything runs inside `holdSteady()`: the category status and its schema are only true while the
 * catalog is held, so the reads and the save they justify share one transaction under the shared
 * lock (ADR-0019 decision 5, ADR-0020 decision 3). The status and schema read for the draft serve
 * the publish too: both describe the listing's category. A repeated submit drafts a second listing —
 * a user action, not a retry; the PRG redirect is what prevents accidental resubmits.
 */
final readonly class DraftListingHandler
{
    public function __construct(
        private ListingRepository $listings,
        private Categories $categories,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * @throws \App\Domain\Listing\Exception\InvalidSellerId
     * @throws \App\Domain\Listing\Exception\InvalidListingTitle       (L-3)
     * @throws \App\Domain\Listing\Exception\InvalidListingDescription (L-4)
     * @throws \App\Domain\Listing\Exception\InvalidPrice              (L-5, incl. an unsupported currency)
     * @throws UnknownCategory                                         (L-2, incl. a malformed id)
     * @throws \App\Domain\Listing\Exception\CategoryIsNotALeaf        (L-2)
     * @throws \App\Domain\Listing\Exception\InvalidAttributeValue     (L-9, incl. a malformed key)
     * @throws \App\Domain\Listing\Exception\ListingIncomplete         (L-13)
     */
    public function __invoke(DraftListing $command): ListingId
    {
        // Released INSIDE the closure, dispatched OUTSIDE it — ReviseListingHandler's reason (ADR-0021
        // d.5): inside `holdSteady()` the save is flushed but not committed.
        [$id, $events] = $this->categories->holdSteady(function () use ($command): array {
            $sellerId = SellerId::fromString($command->sellerId);
            $categoryId = self::categoryId($command->categoryId);
            $status = $this->categories->statusOf($categoryId);
            $schema = $this->categories->schemaOf($categoryId);

            $listing = Listing::draft(
                $this->listings->nextIdentity(),
                $sellerId,
                $categoryId,
                $status,
                ListingTitle::fromString($command->title),
                ListingDescription::fromString($command->description),
                Price::fromDecimal($command->price, Currency::fromCode($command->currency)),
                $schema,
                AttributeInput::fromArray($command->attributes),
            );

            if ($command->publish) {
                $listing->publish($sellerId, $status, $schema, $this->clock->now());
            }

            $this->listings->save($listing);

            return [$listing->id(), $listing->releaseEvents()];
        });

        $this->events->dispatch(...$events);

        return $id;
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
