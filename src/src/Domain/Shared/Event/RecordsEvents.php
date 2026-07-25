<?php

declare(strict_types=1);

namespace App\Domain\Shared\Event;

/**
 * Gives an aggregate root a buffer of events it has recorded but not yet published.
 *
 * The aggregate records; it never dispatches. Dispatching from inside a mutation would publish a
 * fact that a later failure in the same transaction could still roll back, so the Application
 * handler releases the buffer only after a successful save.
 */
trait RecordsEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /**
     * Hands over everything recorded so far and **empties the buffer**.
     *
     * The emptying is the point, not a tidy-up: an aggregate that survives across two handler calls
     * (or is saved twice in one request) would otherwise replay its whole history on every release,
     * and listeners would see the same fact more than once. Releasing is therefore destructive by
     * design, and calling it twice legitimately yields an empty list the second time.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
