<?php

declare(strict_types=1);

namespace App\Domain\Listing\Event;

use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\SellerId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * A listing became public — recorded by every `Listing::publish()` that changed something, so on
 * "publish again" after a withdraw too (it means "became public", which is what Phase 2's alert
 * matching wants). Never on a no-op publish.
 *
 * Shipped with no listener, ADR-0008's `UserRegistered` precedent: the payload is designed as a
 * fact before a consumer can bend it into its own shape.
 *
 * WHAT THE PAYLOAD DELIBERATELY OMITS. Ids and the instant only — no title, price or values. A
 * listener (Notification, Phase 2) reads the committed row; a snapshot here would be a second copy
 * that can disagree with it. And, as with every event, **never a secret** and never the aggregate.
 *
 * Dispatched **after the transaction that saved it commits**, not merely after `save()`: Listing
 * saves inside `Categories::holdSteady()`'s transaction, so the handler releases events inside the
 * closure and dispatches them once it has returned (plan D5) — a listener never sees a publish that
 * rolled back. A crash between commit and dispatch loses the event; accepted while nothing listens.
 */
final readonly class ListingPublished implements DomainEvent
{
    public function __construct(
        private ListingId $listingId,
        private SellerId $sellerId,
        private CategoryId $categoryId,
        private \DateTimeImmutable $publishedAt,
    ) {
    }

    public function listingId(): ListingId
    {
        return $this->listingId;
    }

    public function sellerId(): SellerId
    {
        return $this->sellerId;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function publishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** The moment it became public: the same instant as `publishedAt()`. */
    public function occurredAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }
}
