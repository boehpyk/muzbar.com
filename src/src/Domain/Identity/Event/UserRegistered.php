<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised by `User::register()`.
 *
 * The payload is **value objects only, never the aggregate**. An event has to be a self-contained
 * fact: a listener must be able to act on it minutes later, possibly in another process, without
 * asking whether the object it was handed still says what it said when the event was raised.
 * Passing the mutable `User` would also let any listener call mutators on it — that is precisely
 * how aggregates get corrupted from the outside, and it would put the consistency boundary in the
 * hands of whoever writes the next subscriber.
 *
 * Nothing listens today. It exists now because `identity-email-verification` subscribes to it to
 * send the verification mail, and because a registration that records no fact is a registration
 * that later slices would have to retrofit.
 */
final readonly class UserRegistered implements DomainEvent
{
    public function __construct(
        private UserId $userId,
        private Email $email,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
