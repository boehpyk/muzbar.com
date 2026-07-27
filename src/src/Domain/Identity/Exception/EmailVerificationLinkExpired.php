<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\EmailVerificationRequestId;

/**
 * Invariant I-10: a request presented after `expiresAt` can never be redeemed.
 *
 * The message names the **request id**, not the token — a request id is a server-side handle that
 * an operator can grep for in the table and that grants nobody anything, whereas the token is the
 * credential itself (AC-2). This is the same distinction `WeakPassword` draws between the bound and
 * the value.
 *
 * Nothing about this exception reaches the visitor. The failure contract deliberately renders
 * expired, unknown and malformed as one indistinguishable response (AC-10 … AC-12); the three stay
 * distinct *internally* so that logs and tests can tell them apart, which is precisely the
 * separation between a domain failure and a presentation policy.
 */
final class EmailVerificationLinkExpired extends \DomainException
{
    public static function forRequest(EmailVerificationRequestId $id, \DateTimeImmutable $expiredAt): self
    {
        return new self(\sprintf(
            'Email verification request "%s" expired at %s.',
            $id->toString(),
            $expiredAt->format(\DateTimeInterface::ATOM),
        ));
    }
}
