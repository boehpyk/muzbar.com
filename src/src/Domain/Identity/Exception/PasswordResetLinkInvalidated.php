<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;

/**
 * A link that was alive and correct, and that a **newer request for the same account deliberately
 * killed** (ADR-0011 decision 4).
 *
 * **This exception has no counterpart in `EmailVerificationRequest`, and its existence is inversion
 * #2 of the four.** Slice 2 leaves outstanding verification links alive when a new one is issued,
 * and ADR-0009 decision 5 was right to: once *any* verification token verifies the account, every
 * other live token is inert, because redeeming one hits the already-verified short-circuit. It also
 * wrote the carve-out down in advance — *"this reasoning does not transfer to
 * `identity-password-reset`, where a stale live token is dangerous"* — and this class is that clause
 * being cashed in rather than a fresh argument.
 *
 * Reset has no such inertness property: **each live reset token is an independent, repeatable chance
 * to set a password.** Leaving old ones alive would mean a single stolen link stays useful for its
 * whole hour no matter how many times the legitimate owner reissues — the reissue would then be
 * theatre, giving the victim a feeling of having done something while changing nothing. So
 * `RequestPasswordResetHandler` sweeps the account's outstanding requests through
 * `PasswordResetRequest::invalidate()`, and every one of them answers with this from then on.
 *
 * **It is thrown by `assertRedeemableWith()`, never by `invalidate()`** — invalidating an already
 * invalidated request is a deliberate no-op, because the sweep is a loop a retry or a concurrent
 * request may legitimately run twice.
 *
 * The accepted UX cost is real and is why the per-user cap is three rather than five: request twice,
 * click the mail that arrived first, and this is what the system decided. The visitor is not told
 * that, though — it collapses into the same neutral invalid-link response as expired, spent, unknown
 * and malformed (AC-16), and is distinguishable only in a log and in a test.
 *
 * The message carries a request id and an instant, never a token.
 */
final class PasswordResetLinkInvalidated extends \DomainException
{
    public static function forRequest(PasswordResetRequestId $id, \DateTimeImmutable $invalidatedAt): self
    {
        return new self(\sprintf(
            'Password reset request "%s" was invalidated at %s by a newer request.',
            $id->toString(),
            $invalidatedAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
