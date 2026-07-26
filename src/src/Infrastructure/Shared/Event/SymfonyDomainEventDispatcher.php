<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Event;

use App\Domain\Shared\Event\DomainEvent;
use App\Domain\Shared\Port\DomainEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The `DomainEventDispatcher` port, forwarding to Symfony's synchronous event dispatcher.
 *
 * WHY THE DOMAIN EVENT IS DISPATCHED AS-IS.
 * Symfony has accepted any object as an event since 4.3, so `UserRegistered` *is* the Symfony
 * event and its FQCN *is* the event name. No envelope, no adapter event class, no name mapping —
 * a listener subscribes to `UserRegistered::class` and receives the domain fact untouched. An
 * intermediate wrapper would buy nothing and would force every listener to unwrap.
 *
 * WHY THE PSR INTERFACE RATHER THAN SYMFONY'S.
 * `dispatch(object $event): object` is the whole surface this adapter needs. Depending on the
 * narrower contract means swapping the transport later (Messenger, an outbox table) touches this
 * one class and nothing that listens.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: nothing here is transactional. The caller (the Application
 * handler) dispatches only after a successful save, so a listener never sees a rolled-back fact —
 * but a crash between flush and dispatch loses the event. That is a knowingly accepted gap while
 * no listener is load-bearing; `identity-email-verification` is the slice that must revisit it.
 */
final readonly class SymfonyDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
