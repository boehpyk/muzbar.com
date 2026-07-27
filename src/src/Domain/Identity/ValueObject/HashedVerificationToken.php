<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedVerificationToken;

/**
 * An opaque wrapper around whatever digest the Infrastructure produced from a `VerificationToken`.
 *
 * Its job is to make a *type* out of the sentence "this string is a digest, not a token", which is
 * what makes `EmailVerificationRequest::issue(..., string $token)` literally unwriteable. The
 * aggregate can then be trusted, by signature alone, never to have held the plaintext — which is
 * most of AC-2 and AC-30 discharged by the type system rather than by vigilance.
 *
 * DELIBERATELY NO FORMAT VALIDATION. Asserting "64 lower-case hex characters" would encode SHA-256
 * — an Infrastructure choice, made in `RandomVerificationTokenGenerator` — into a Domain rule, so
 * moving to BLAKE2 or a truncated digest would start failing domain invariants for no business
 * reason. Exactly the argument `HashedPassword` makes. All this value object asserts is that
 * something is stored and that it fits the column.
 *
 * WHY THE DIGEST IS DELIBERATELY FAST (ADR-0009, decision 2). The reflex, having just written
 * `HashedPassword`, is "hash it with Argon2 like the password". That would be cargo cult. A password
 * needs a slow key-derivation function because it is low-entropy and *guessable offline*: an
 * attacker with the digest can try `password123` and a billion of its friends, and the KDF's whole
 * purpose is to make each of those guesses expensive. A verification token is 256 bits of CSPRNG
 * output — there is no dictionary, no pattern and no feasible search, so slowing each guess down by
 * a factor of a million changes an already-impossible attack into an equally impossible one while
 * adding real latency to every click on a link. Same *pattern* as `PlainPassword`/`HashedPassword`,
 * different *reason*, and knowing which is which is the difference between applying a practice and
 * understanding it.
 *
 * What the digest *is* still for: a database dump must not be a set of working account-takeover
 * URLs. That property needs the pre-image to be irrecoverable, which a fast hash of a
 * high-entropy input gives just as completely as a slow one.
 */
final readonly class HashedVerificationToken
{
    /** Matches the `token_hash` column width; public so the mapping can quote it. */
    public const int MAX_LENGTH = 255;

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * The value is *not* trimmed. A digest is opaque, so we have no business deciding that any of
     * its bytes are decorative.
     */
    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw InvalidHashedVerificationToken::empty();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidHashedVerificationToken::tooLong(self::MAX_LENGTH);
        }

        return new self($value);
    }

    /**
     * Value equality in **constant time** (AC-31) — and here, unlike `HashedPassword::equals()`,
     * that comparison genuinely *is* the credential check.
     *
     * A plain `===` on strings short-circuits at the first differing byte, so the time it takes to
     * fail leaks how many leading characters were right. That turns a 2^256 search into a
     * character-by-character one for an attacker who can measure the response, which is the whole
     * class of bug `hash_equals` exists to close. The comparison is safe to do here — rather than
     * needing the hasher, as passwords do — precisely because this digest is unsalted and
     * deterministic: the same token always yields the same string.
     *
     * `hash_equals` is a core PHP function, not a library, so using it costs the Domain no purity.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
