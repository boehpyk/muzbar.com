<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\PasswordResetRequestId;

/**
 * Invariant I-16: a request is redeemed **at most once**; `redeemedAt` moves `null → instant` and
 * never back. Also half of invariant I-17 — `invalidate()` throws this rather than superseding a
 * request that has already been spent.
 *
 * **THE CONTRAST WITH `EmailVerificationLinkAlreadyRedeemed` IS THE WHOLE POINT OF THIS CLASS, AND
 * IT MUST NOT BE "ALIGNED".** In slice 2 that sibling exception is nearly unreachable: a replayed
 * verification link is a **friendly no-op**, because mail scanners prefetch links and the human's
 * click is therefore often *already* the replay, so `VerifyEmailWithTokenHandler` short-circuits on
 * an already-verified user and never calls `redeem()`. Punishing that replay would make a correct
 * flow look broken to a real person.
 *
 * Here the replay is **refused**, and this exception is an ordinary, reachable outcome (AC-18). The
 * difference is not a change of mind about ergonomics; it is a change of *effect*. Verifying an
 * already-verified address is idempotent and harmless. Setting a password is **destructive and
 * repeatable**, so absorbing a replay would leave a spent link still able to reach a
 * password-setting form — which is precisely the primitive that making the challenge single-use
 * exists to destroy. "Already used" is the honest answer.
 *
 * Nothing is lost by refusing, because the prefetch problem is solved elsewhere and better:
 * `GET /reset-password/{token}` mutates nothing (ADR-0011 decision 9), so a scanner cannot spend a
 * link at all, and the only way to reach this exception is a genuine second POST.
 *
 * The message carries a request id and an instant, never a token.
 */
final class PasswordResetLinkAlreadyUsed extends \DomainException
{
    public static function forRequest(PasswordResetRequestId $id, \DateTimeImmutable $redeemedAt): self
    {
        return new self(\sprintf(
            'Password reset request "%s" was already used at %s.',
            $id->toString(),
            $redeemedAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
