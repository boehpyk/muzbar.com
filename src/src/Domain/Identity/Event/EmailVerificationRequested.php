<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised by `EmailVerificationRequest::issue()`.
 *
 * The fact it records is *"we asked this account to prove its address at this time, and the
 * challenge dies at that time"*. That is a genuinely auditable statement — it answers "how many
 * times did we mail this person?" and "was a live challenge outstanding when the support ticket was
 * filed?" without anyone having to keep the row forever.
 *
 * **Nobody listens to it today**, and that is a deliberate, recorded judgement (technical plan,
 * Risk 6) rather than speculative generality: read it as history the system chose to keep, the same
 * call slice 1 made with `UserRegistered`. Note especially that the verification *mail* is **not**
 * sent by a listener on this event — see `VerificationMailer` for why routing the secret through an
 * event bus would be an elegant-looking way to leak it.
 *
 * WHAT THE PAYLOAD DELIBERATELY OMITS (AC-26). No `VerificationToken`, and no `Email`.
 *
 * The token first: a secret placed inside an event is a secret in every listener that ever
 * subscribes, in every log line that dumps the event for debugging, and — the day the transport goes
 * asynchronous — in every row of the queue table, at rest, for as long as the retry policy keeps it.
 * None of those places is auditable and none of them is under this class's control. The only
 * component that legitimately needs the plaintext is the mailer, and the Application handler hands
 * it there directly.
 *
 * The address second, for a quieter reason: it is personal data with no purpose here. A listener
 * that wants it can load the user by `UserId`, which is exactly the check we want such a listener to
 * have to pass. `UserRegistered` carries an `Email` because the fact "*this address* registered" is
 * incomplete without it; "a challenge was issued to user X" is complete without it.
 *
 * As with every event in the system, the payload is **value objects only, never the aggregate**: an
 * event must be a self-contained fact that a listener can act on later, in another process, without
 * asking whether the object it holds still says what it said.
 */
final readonly class EmailVerificationRequested implements DomainEvent
{
    public function __construct(
        private EmailVerificationRequestId $requestId,
        private UserId $userId,
        private \DateTimeImmutable $issuedAt,
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public function requestId(): EmailVerificationRequestId
    {
        return $this->requestId;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * The instant of issuance *is* the instant this fact happened, so there is no separate
     * `occurredAt` field to drift out of step with `issuedAt`. Two timestamps that must always be
     * equal are one timestamp with an extra way to be wrong.
     */
    public function occurredAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
