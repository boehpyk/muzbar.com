<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * Something that was presented as a password-reset request id is not shaped like one.
 *
 * Unlike the token exceptions in this directory, this one *does* interpolate the offending value:
 * a request id is a server-side identifier, never a secret, and the only useful diagnosis for a
 * malformed one is seeing what was actually passed. AC-10's rule is about plaintext tokens and
 * passwords; ids and user ids are explicitly allowed in messages.
 */
final class InvalidPasswordResetRequestId extends \DomainException
{
    public static function malformed(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid password reset request id.', $value));
    }
}
