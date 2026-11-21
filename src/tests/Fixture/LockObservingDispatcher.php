<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Shared\Event\DomainEvent;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Records each dispatched event with whether `Categories::holdSteady()` was active at that moment
 * (the shared `ObservedCategories`). `ListingPublished` must go out only after the transaction that
 * saved it has committed, which for Listing means after `holdSteady()` has returned (ADR-0008
 * amendment, listing-publish plan D5).
 */
final class LockObservingDispatcher implements DomainEventDispatcher
{
    /** @var list<array{DomainEvent, bool}> */
    private array $dispatched = [];

    public function __construct(
        private readonly ObservedCategories $categories,
    ) {
    }

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatched[] = [$event, $this->categories->isHeld()];
        }
    }

    /**
     * @return list<array{DomainEvent, bool}> each event and "inside holdSteady() when dispatched"
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
