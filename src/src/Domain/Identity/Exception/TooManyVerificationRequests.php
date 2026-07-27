<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\UserId;

/**
 * The anti-abuse rule I-12 refusing a sixth issuance inside a rolling hour.
 *
 * This is the *domain* limit — "we will not mail one person more than five challenges an hour",
 * counted per account by `RequestEmailVerificationHandler` over
 * `EmailVerificationRequestRepository::countIssuedForUserSince()`. It is a different rule from the
 * per-IP rate limiter at the HTTP boundary, and they exist in two places on purpose: the limiter
 * stops one machine hammering the endpoint, this stops one *mailbox* being used as a weapon no
 * matter how many machines request it. Either alone leaves an obvious hole.
 *
 * **The bound itself lives on the aggregate**, as `EmailVerificationRequest::MAX_ISSUES_PER_HOUR`,
 * not here. Policy belongs with the concept it governs; an exception is where a rule is *reported*,
 * never where it is *defined*, or the number ends up quoted in two places that drift.
 *
 * Like `EmailAlreadyVerified`, this is a normal outcome that the domain simply cannot proceed past,
 * and the visitor never learns it happened — the resend form renders the identical neutral response
 * it renders for success (AC-15, AC-17). The message names the user id, never the address.
 */
final class TooManyVerificationRequests extends \DomainException
{
    public static function forUser(UserId $id): self
    {
        return new self(\sprintf(
            'Too many email verification requests have been issued for user "%s" in the last hour.',
            $id->toString(),
        ));
    }
}
