<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedResetToken;

/**
 * An opaque wrapper around whatever digest the Infrastructure produced from a `ResetToken`.
 *
 * Its job is to make a *type* out of the sentence "this string is a digest, not a token", which is
 * what makes `PasswordResetRequest::issue(..., string $token)` literally unwriteable. The aggregate
 * can then be trusted, by signature alone, never to have held the plaintext — which is most of AC-10
 * and AC-35 discharged by the type system rather than by vigilance. A dump of
 * `identity_password_reset_request` is then a table of digests, not a list of working
 * account-takeover URLs.
 *
 * DELIBERATELY NO FORMAT VALIDATION. Asserting "64 lower-case hex characters" would encode SHA-256 —
 * an Infrastructure choice, made in `RandomResetTokenGenerator` — into a Domain rule, so moving to
 * BLAKE2, to a truncated digest, or to a base64-encoded one would start failing domain invariants
 * for no business reason. Exactly the argument `HashedPassword` and `HashedVerificationToken` make.
 * All this value object asserts is that something is stored and that it fits the column.
 *
 * WHY THE DIGEST IS DELIBERATELY FAST, RESTATED RATHER THAN CROSS-REFERENCED (ADR-0011 decision 2).
 * `HashedVerificationToken` already carries this argument, and repeating it is not an oversight: the
 * word **password** in this slice's name, in this class's name, and in the name of the flow makes
 * the wrong reflex measurably stronger than it was in slice 2. Anyone reading `HashedResetToken`
 * next to `HashedPassword` will feel the pull of "hash it with Argon2 like the other one", and a
 * cross-reference to a sibling file is a weaker defence against a reflex than the reasoning written
 * where the reflex fires.
 *
 * So: a **password** needs a slow key-derivation function because it is low-entropy and *guessable
 * offline*. An attacker holding the digest can try `password123` and a billion of its friends, and
 * the KDF's whole purpose is to make each of those guesses expensive enough that the billion never
 * finishes. A **reset token** is 256 bits of CSPRNG output. There is no dictionary, no pattern, no
 * structure and no feasible search, so there is no guessable pre-image at any cost — slowing each
 * guess by a factor of a million turns an already-impossible attack into an equally impossible one,
 * while adding real latency to *every* click on *every* reset link, on the endpoint people reach
 * when they are already locked out and least patient. Same *pattern* as
 * `PlainPassword`/`HashedPassword`, different *reason*, and knowing which is which is the difference
 * between applying a practice and understanding it.
 *
 * What the digest *is* still for, and it is not weakened by being fast: a database dump must not be
 * a set of working account-takeover URLs. That property needs the pre-image to be irrecoverable,
 * which a fast hash of a high-entropy input gives just as completely as a slow one.
 */
final readonly class HashedResetToken
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
            throw InvalidHashedResetToken::empty();
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidHashedResetToken::tooLong(self::MAX_LENGTH);
        }

        return new self($value);
    }

    /**
     * Value equality in **constant time** (AC-27) — and here, as with `HashedVerificationToken` and
     * unlike `HashedPassword::equals()`, that comparison genuinely *is* the credential check. It is
     * the last gate in `PasswordResetRequest::assertRedeemableWith()`, and what stands behind it is
     * the account itself.
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
