<?php

declare(strict_types=1);

namespace App\Domain\Shared\Event;

/**
 * A fact that has already happened inside a bounded context.
 *
 * Past tense is not a naming whim: an event is immutable history, so it can be published, replayed
 * or ignored, but never refused. The only thing every event in the system must agree on is *when*
 * it happened — everything else is context-specific payload.
 */
interface DomainEvent
{
    public function occurredAt(): \DateTimeImmutable;
}
