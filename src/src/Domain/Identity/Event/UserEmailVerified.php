<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised by the **first** successful `User::verifyEmail()` call and never by a repeat, because the
 * aggregate is idempotent — a fact that already happened does not happen again.
 *
 * Carries the `UserId` and nothing else: a listener that needs the address looks the user up
 * through the repository rather than trusting a copy embedded in the event. Same rule as
 * `UserRegistered` — value objects only, never the aggregate.
 */
final readonly class UserEmailVerified implements DomainEvent
{
    public function __construct(
        private UserId $userId,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
