<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * Something that was presented as a password-reset token is not shaped like one.
 *
 * The named constructor takes the **rule that was broken**, never the value that broke it — the same
 * discipline `InvalidVerificationToken` follows, and here the stakes are one notch higher. A
 * malformed reset token is very often a *real, live* token that a mail client mangled by wrapping a
 * line or that someone truncated while pasting, and this one is an account-takeover primitive rather
 * than a proof of reachability. Interpolating "the invalid value" into the message would therefore
 * write working credentials into every log sink the application has (AC-10). There is nothing an
 * operator could do with the string anyway: the only diagnosis worth having is "the shape was
 * wrong", and that is what this says.
 */
final class InvalidResetToken extends \DomainException
{
    public static function malformed(int $expectedLength): self
    {
        return new self(\sprintf(
            'A password reset token must be exactly %d URL-safe base64 characters.',
            $expectedLength,
        ));
    }
}
