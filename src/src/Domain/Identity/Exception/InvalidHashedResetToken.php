<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * The stored digest of a password-reset token is not storable.
 *
 * Messages describe the *digest*, never the token it was derived from, and never the digest's own
 * value either — a digest is not a secret, but exception messages reach logs and a value nobody can
 * act on is a value not worth logging. This mirrors `InvalidHashedVerificationToken` exactly, which
 * is the point: the pair `ResetToken`/`HashedResetToken` repeats the shape of
 * `VerificationToken`/`HashedVerificationToken`, so it repeats its failure vocabulary too.
 */
final class InvalidHashedResetToken extends \DomainException
{
    public static function empty(): self
    {
        return new self('A password reset token hash may not be empty.');
    }

    public static function tooLong(int $maximum): self
    {
        return new self(\sprintf('A password reset token hash may not exceed %d characters.', $maximum));
    }
}
