<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;

/**
 * Invariant I-18: a request presented after `expiresAt` can never be redeemed.
 *
 * It is the exception this slice expects to see **most often in normal life**, and by design: the
 * one-hour lifetime (ADR-0011 decision 3) is a quarter of a day shorter than a verification link's,
 * so a link found in an inbox the next morning is dead on purpose. That is the cost the short window
 * was bought with, and the invalid-link response is a redirect back to the request form precisely so
 * that paying it costs one click.
 *
 * The message names the **request id** and the deadline, never the token — a request id is a
 * server-side handle an operator can grep for in the table and that grants nobody anything, whereas
 * the token *is* the credential (AC-10).
 *
 * Nothing about this exception reaches the visitor. The failure contract renders expired,
 * superseded, spent, unknown and malformed as **one indistinguishable response** (AC-16); the five
 * stay distinct *internally* so that logs and tests can tell them apart, which is exactly the
 * separation between a domain failure and a presentation policy.
 */
final class PasswordResetLinkExpired extends \DomainException
{
    public static function forRequest(PasswordResetRequestId $id, \DateTimeImmutable $expiredAt): self
    {
        return new self(\sprintf(
            'Password reset request "%s" expired at %s.',
            $id->toString(),
            $expiredAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
