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
 * **Nobody listens to it today**, and that is deliberate and recorded (ADR-0011 decision 9). It is
 * not speculative generality: it clears the same two-part bar the other unlistened events clear — it
 * names a fact a domain expert would recognise without being taught the code, and its payload is
 * complete without a second query.
 *
 * **It is also the hook for a specific, already-scheduled thing**, which is what separates it from
 * an event kept "just in case": the *"your password was changed"* security notification, deferred
 * out of this slice and on the roadmap as `identity-password-changed-notification`. That mail is the
 * mechanism by which the victim of an account takeover finds out — the attacker owns the account
 * from the moment the reset completes, and the *only* signal that reaches a channel the attacker
 * does not yet control is a message sent to the old address the instant this event is dispatched.
 * The listener will subscribe here and need nothing else added to the payload.
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
