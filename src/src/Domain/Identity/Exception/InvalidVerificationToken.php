<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * Something that was presented as a verification token is not shaped like one.
 *
 * The named constructor takes the **rule that was broken**, never the value that broke it — exactly
 * the discipline `WeakPassword` follows, and for a sharper reason. A malformed token is still very
 * often a *real* token that a mail client mangled by wrapping a line or stripping a trailing
 * character, so interpolating "the invalid value" into the message would write live secrets into
 * every log sink the application has (AC-2). There is nothing an operator could do with the string
 * anyway: the only diagnosis worth having is "the shape was wrong", and that is what this says.
 */
final class InvalidVerificationToken extends \DomainException
{
    public static function malformed(int $expectedLength): self
    {
        return new self(\sprintf(
            'A verification token must be exactly %d URL-safe base64 characters.',
            $expectedLength,
        ));
    }
}
