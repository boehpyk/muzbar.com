<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * The named constructors take the **bound that was violated**, never the password and never its
 * actual length. Exception messages end up in logs, in Sentry and occasionally on a screen; a
 * message that leaked "your 7-character password" would hand an attacker a free constraint on the
 * search space, and one that interpolated the password itself would be a breach.
 */
final class WeakPassword extends \DomainException
{
    public static function tooShort(int $minimum): self
    {
        return new self(\sprintf('A password must be at least %d characters long.', $minimum));
    }

    public static function tooLong(int $maximum): self
    {
        return new self(\sprintf('A password may not exceed %d bytes.', $maximum));
    }
}
