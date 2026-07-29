<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\UserId;

/**
 * The anti-abuse rule I-21 refusing a fourth issuance inside a rolling hour.
 *
 * This is the *domain* limit — "we will not mail one person more than three reset challenges an
 * hour" — counted per account by `RequestPasswordResetHandler` over
 * `PasswordResetRequestRepository::countIssuedForUserSince()`. It is a different rule from the
 * per-IP rate limiter at the HTTP boundary, and they exist in two places on purpose: the limiter
 * stops one machine hammering the endpoint, this stops one *mailbox* being used as a weapon no
 * matter how many machines request it. Either alone leaves an obvious hole.
 *
 * **It is checked before the reissue sweep**, which is the detail that makes it a defence rather
 * than a nuisance: if the cap were checked after, an attacker who could not obtain a link could
 * still spam the form to kill a victim's in-flight one, turning the anti-abuse rule into a
 * denial-of-recovery tool (AC-7).
 *
 * **The bound itself lives on the aggregate**, as `PasswordResetRequest::MAX_ISSUES_PER_HOUR`, not
 * here. Policy belongs with the concept it governs; an exception is where a rule is *reported*,
 * never where it is *defined*, or the number ends up quoted in two places that drift.
 *
 * THESE ARE NORMAL OUTCOMES, NOT ERRORS — and this is worth stating once, plainly, because this
 * slice throws a lot of exceptions at things that are not going wrong. An unknown address at the
 * forgot-password form, a fourth request inside an hour, an address in the gap between the form
 * validator and `Email` — none of those is a bug, a broken invariant or an operational incident.
 * They are exceptions because the **domain genuinely cannot proceed**: there is no request to issue
 * and no mail to send, so `void` would be a lie about what happened.
 *
 * Collapsing them into one indistinguishable response is a **presentation** policy, and it belongs
 * at the boundary — the public form renders the identical neutral "if that address exists, we sent a
 * link" for all of them plus success (AC-5, AC-6), precisely so that the page cannot be used to sort
 * a list of addresses into "has an account here" and "does not". Keeping them distinct down here is
 * what lets a **second adapter** — a console tool, an admin action, a support workflow — treat the
 * same event as a real anomaly worth logging loudly, where the public form must reveal nothing. A
 * design that makes the neutral answer the only answer available cannot ever have that second
 * adapter.
 *
 * The message names the user id, never the address: an operator can grep for it, and it grants
 * nobody anything.
 */
final class TooManyPasswordResetRequests extends \DomainException
{
    public static function forUser(UserId $id): self
    {
        return new self(\sprintf(
            'Too many password reset requests have been issued for user "%s" in the last hour.',
            $id->toString(),
        ));
    }
}
