<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\Email;

/**
 * Raised on both paths to a duplicate: the handler's `existsByEmail()` pre-check and the adapter's
 * translation of the database unique-constraint violation. One exception for both is the point —
 * the HTTP layer maps it to a single fixed sentence, so a user who loses the race sees exactly
 * what a user who does not sees (AC-9).
 *
 * Carrying the email is acceptable here because this message is server-side only. It must never be
 * echoed back verbatim; the address is for the operator reading the log, not for the visitor.
 */
final class EmailAlreadyRegistered extends \DomainException
{
    public static function withEmail(Email $email): self
    {
        return new self(\sprintf('An account with email "%s" already exists.', $email->toString()));
    }
}
