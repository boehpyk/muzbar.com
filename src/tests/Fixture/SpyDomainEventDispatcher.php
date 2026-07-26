<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Shared\Event\DomainEvent;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * A test double for the `DomainEventDispatcher` port that records everything it was asked to
 * dispatch, instead of forwarding to Symfony's event dispatcher.
 *
 * Used by the handler integration tests to assert exactly which domain events a use case
 * published (AC-27) without depending on Symfony's dispatcher wiring at all.
 */
final class SpyDomainEventDispatcher implements DomainEventDispatcher
{
    /** @var list<DomainEvent> */
    private array $dispatched = [];

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatched[] = $event;
        }
    }

    /**
     * @return list<DomainEvent>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
