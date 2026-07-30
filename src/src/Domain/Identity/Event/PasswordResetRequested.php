<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised by `PasswordResetRequest::issue()`.
 *
 * The fact it records is *"we issued this account a password-reset challenge at this time, and the
 * challenge dies at that time"*. That is a genuinely auditable statement, and on this flow it is the
 * one most likely to be read in anger: "how many reset links were minted for this account, and
 * when?" is the first question of every account-takeover post-mortem, and it stays answerable even
 * after the rows are pruned.
 *
 * **Nobody listens to it today**, and that is a deliberate, recorded judgement rather than
 * speculative generality — the same call slice 1 made with `UserRegistered` and slice 2 with
 * `EmailVerificationRequested`. The bar an unlistened event has to clear is twofold: it must name a
 * fact a **domain expert would recognise** without being taught the code, and its payload must be
 * **complete without a second query**. "This account was sent a reset challenge, valid until then"
 * clears both. `PasswordResetRequestInvalidated` clears neither, which is why it does not exist
 * (AC-34) — invalidation is bookkeeping the system does to itself, not a fact anyone outside the
 * aggregate would name.
 *
 * Note especially that the reset *mail* is **not** sent by a listener on this event. That is the
 * same deliberate asymmetry slice 2 chose, for a sharper reason here: the Application handler calls
 * the `PasswordResetMailer` port directly because it is the only place in the system that
 * legitimately holds the plaintext token, and routing a live account-takeover credential through an
 * event bus to reach a listener would be the more elegant-looking wiring and the worse design.
 *
 * WHAT THE PAYLOAD DELIBERATELY OMITS (AC-32). No `ResetToken`, and no `Email`.
 *
 * The token first, and this is the non-negotiable half: **a secret placed inside an event is a
 * secret in every listener that ever subscribes, in every log line that dumps the event for
 * debugging, and — the moment the transport goes asynchronous — in every row of the
 * `messenger_messages` table, at rest, for as long as the retry policy keeps it.** None of those
 * places is auditable, none of them is under this class's control, and unlike a verification token
 * this one is enough to take the account. The only component that needs the plaintext is the mailer,
 * and the handler hands it there directly.
 *
 * The address second, for a quieter reason: it is personal data with no purpose here. A listener
 * that wants it can load the user by `UserId`, which is exactly the check we want such a listener to
 * have to pass. `UserRegistered` carries an `Email` because the fact "*this address* registered" is
 * incomplete without it; "a reset challenge was issued to user X" is complete without it.
 *
 * As with every event in the system, the payload is **value objects only, never the aggregate**: an
 * event must be a self-contained fact that a listener can act on later, in another process, without
 * asking whether the object it holds still says what it said.
 */
final readonly class PasswordResetRequested implements DomainEvent
{
    public function __construct(
        private PasswordResetRequestId $requestId,
        private UserId $userId,
        private \DateTimeImmutable $issuedAt,
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public function requestId(): PasswordResetRequestId
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
