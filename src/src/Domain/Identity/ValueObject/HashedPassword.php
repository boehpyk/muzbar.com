<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedPassword;

/**
 * An opaque wrapper around whatever the Infrastructure hasher produced.
 *
 * Its job is to make a *type* out of the sentence "this string is a hash, not a password", which
 * is what makes `User::register(..., string $password)` literally unwriteable — the compiler now
 * enforces invariant I-2 instead of a code reviewer.
 *
 * DELIBERATELY NO FORMAT VALIDATION. Checking for a `$2y$` or `$argon2` prefix would bake the
 * current algorithm choice into the Domain, so swapping bcrypt for Argon2id — a pure
 * Infrastructure decision, made in `security.yaml` — would start failing domain rules. The Domain
 * never inspects, parses, compares or re-derives a hash; the Symfony Security layer does all of
 * that (ADR-0005). All this VO asserts is that something is stored and that it fits the column.
 */
final readonly class HashedPassword
{
    /** Matches the `password_hash` column width; public so the mapping can quote it. */
    public const int MAX_LENGTH = 255;

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * The value is *not* trimmed. A hash is opaque, so we have no business deciding that its
     * leading or trailing bytes are decorative.
     */
    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw InvalidHashedPassword::empty();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidHashedPassword::tooLong(self::MAX_LENGTH);
        }

        return new self($value);
    }

    /**
     * Value equality only — this is never a credential check. Comparing a login attempt against a
     * stored hash requires the hasher (salts make two hashes of one password differ), and that
     * comparison belongs to the Security layer.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
