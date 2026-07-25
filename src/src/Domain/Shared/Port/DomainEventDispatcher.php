<?php

declare(strict_types=1);

namespace App\Domain\Shared\Port;

use App\Domain\Shared\Event\DomainEvent;

/**
 * Publishes recorded domain events to whatever listens.
 *
 * The port exists so the Application layer can publish without naming a transport. Today the
 * adapter forwards to Symfony's synchronous event dispatcher; swapping in Messenger, or an
 * outbox table, is an Infrastructure change that no handler notices.
 *
 * The variadic signature accepts zero events on purpose — an idempotent operation that changed
 * nothing releases an empty buffer, and the handler should not have to guard against that.
 */
interface DomainEventDispatcher
{
    public function dispatch(DomainEvent ...$events): void;
}
