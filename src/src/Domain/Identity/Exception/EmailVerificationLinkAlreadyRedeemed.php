<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\EmailVerificationRequestId;

/**
 * Invariant I-9: a request is redeemed **at most once**; `redeemedAt` moves `null → instant` and
 * never back.
 *
 * Worth being clear about who actually sees this, because the obvious reading is wrong. The common
 * case of "this link was already used" — a user clicking a link their mail client pre-fetched — does
 * **not** reach here at all: `VerifyEmailWithTokenHandler` short-circuits on an already-verified
 * user *before* calling `redeem()`, and answers with a friendly "already verified" (AC-8). A flow
 * that punished the replay would look broken to a real person.
 *
 * What is left, therefore, is the state "this request is redeemed but its user is somehow *not*
 * verified" — reachable only by data corruption or by a hand-edited row. It is refused loudly rather
 * than accepted silently (AC-9), and the handler logs it, because an unreachable state that occurs
 * is information.
 *
 * The message carries a request id, never a token.
 */
final class EmailVerificationLinkAlreadyRedeemed extends \DomainException
{
    public static function forRequest(EmailVerificationRequestId $id, \DateTimeImmutable $redeemedAt): self
    {
        return new self(\sprintf(
            'Email verification request "%s" was already redeemed at %s.',
            $id->toString(),
            $redeemedAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
