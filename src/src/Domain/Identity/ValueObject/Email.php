<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidEmail;

/**
 * A syntactically valid, normalised email address — the natural key of a User and its login
 * identifier.
 *
 * WHY WE LOWER-CASE THE LOCAL PART TOO.
 * RFC 5321 permits the part before the `@` to be case-sensitive: `Max@example.com` and
 * `max@example.com` are, on paper, two different mailboxes. We deliberately ignore that. Every
 * mail provider a musician actually uses treats the local part case-insensitively, so honouring
 * the RFC would let one human hold two accounts by capitalising a letter — and it would break
 * account linking in `identity-google-oauth`, which matches a Google identity to an existing
 * account *by email*. A case-sensitive key would silently create an orphaned duplicate instead.
 * The cost of the choice is a theoretical address we cannot serve; the cost of the alternative is
 * duplicate humans. Recorded here so the trade-off reads as a decision, not an oversight.
 */
final readonly class Email
{
    /**
     * Also the width of the `email` column and of the form's `Length` constraint. Public so those
     * two places can quote this constant instead of repeating the number.
     */
    public const int MAX_LENGTH = 180;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalised = mb_strtolower(trim($value));

        if (false === filter_var($normalised, \FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmail::malformed($normalised);
        }

        // Measured after normalisation: trimming can only shorten, and the stored value is what
        // has to fit the column.
        $length = mb_strlen($normalised);

        if ($length > self::MAX_LENGTH) {
            throw InvalidEmail::tooLong($length);
        }

        return new self($normalised);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
