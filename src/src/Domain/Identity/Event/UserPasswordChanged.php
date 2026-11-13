<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised by every successful `User::changePassword()`.
 *
 * Unlike `UserEmailVerified`, which fires once and never again because the aggregate short-circuits
 * a repeat, this one fires on **every** change — because every change is a new fact. A password
 * being set twice is two things happening, not one thing being re-announced, and that difference is
 * the whole reason `changePassword()` is not idempotent while `verifyEmail()` is.
 *
 * **Its listener is `Infrastructure\Identity\EventListener\NotifyUserOnPasswordChanged`**, which
 * sends the *"your password was changed"* security notice (slice `identity-password-changed-
 * notification`). It shipped with no Domain or Application change and nothing added to this payload
 * — the promise made when the event shipped unlistened (ADR-0011 decision 9) and kept. The notice is
 * how an account holder learns of a change they did not make, unless the attacker also owns the
 * mailbox; it becomes decisive once an authenticated change-password path exists, which this
 * listener covers automatically because it subscribes to the fact rather than to the reset use case.
 *
 * WHAT THE PAYLOAD DELIBERATELY OMITS (AC-33). No `HashedPassword`, and obviously no plaintext.
 *
 * The plaintext is never in scope — the aggregate has never seen one, by signature. The **hash** is
 * the interesting omission, because it is the one that looks harmless: it is already a slow-KDF
 * digest, so surely it is safe to carry? No. An event travels into every listener, every debug dump
 * and every `messenger_messages` row, and a credential digest sitting at rest in a queue table is a
 * credential digest in a place with none of the access controls the users table has, offered up for
 * offline cracking against exactly the low-entropy inputs Argon2 exists to make expensive. Nothing a
 * listener could legitimately want to do with this fact requires the hash; the notification mail
 * needs the `UserId` and the instant, and reads the address through the repository.
 *
 * As with every event in the system, the payload is **value objects only, never the aggregate**.
 */
final readonly class UserPasswordChanged implements DomainEvent
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
