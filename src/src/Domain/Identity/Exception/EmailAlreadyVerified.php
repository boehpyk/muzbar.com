<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\UserId;

/**
 * Somebody asked for a verification link for an account whose address is already verified.
 *
 * **This is a normal outcome, not an error**, and it is worth being explicit about why it is an
 * exception anyway. The *domain* genuinely cannot proceed: there is nothing to issue, and issuing
 * anyway would mail a live credential to an account that needs none. So the use case stops, and
 * stopping is what an exception expresses.
 *
 * What happens next is a **presentation** decision, and it differs by caller — which is exactly the
 * reason it must not be baked in here. The public resend form catches this and renders the same
 * neutral response it renders for every other input (AC-15), because telling a stranger "that
 * address is already verified" is a free account-enumeration oracle. The registration listener, by
 * contrast, should treat it as a genuine anomaly worth logging. One handler serves both because it
 * reports the fact and declines to decide what the fact means.
 *
 * The message names the **user id**, not the address (Constitution §8): a user id is a server-side
 * handle, and an address in a log line is personal data that nobody needed.
 */
final class EmailAlreadyVerified extends \DomainException
{
    public static function forUser(UserId $id): self
    {
        return new self(\sprintf('The email address of user "%s" is already verified.', $id->toString()));
    }
}
