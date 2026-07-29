<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidPasswordResetRequestId;

/**
 * The identity of a PasswordResetRequest, as a value.
 *
 * There is deliberately **no `generate()` method here.** Minting an id means choosing a UUID version
 * and an implementation (`symfony/uid`), which is a vendor decision and therefore an Infrastructure
 * one — `PasswordResetRequestRepository::nextIdentity()` owns it (ADR-0007). The Domain only states
 * what a valid id *looks like*, which it can do with a regex and no dependency at all.
 *
 * The format check is intentionally version-agnostic: it accepts any RFC 4122 layout. Pinning it to
 * UUIDv7 would encode a persistence-performance choice (time-ordered keys keep B-tree pages from
 * fragmenting) into a domain rule, and would reject perfectly valid ids minted by a future adapter
 * or by a fixture.
 *
 * ON BEING THE **THIRD** NEAR-CLONE — this is the file ADR-0009 pointed at, and it is a knowing
 * choice rather than an oversight. Line for line this class is `UserId` and
 * `EmailVerificationRequestId` with two identifiers changed. ADR-0009's consequence said *"two is a
 * coincidence, three is a pattern — revisit when `Catalog` produces the third example"*, and here is
 * the third example, arriving early and from the wrong place. The answer is still **no**, and
 * ADR-0009's **2026-07-29 amendment** replaces the headcount with a criterion, because the count was
 * always a proxy for the real question:
 *
 * > *Revisit at the first aggregate id outside `Identity`, and only if the extraction can preserve
 * > cross-type comparison as a compile-time error.*
 *
 * Both halves matter here. **First**, all three examples come from one bounded context, so an
 * abstraction induced from them is an `Identity` abstraction wearing a `Shared/` namespace.
 * `Catalog` was named as the trigger precisely *because* it is a different context — that is what
 * tests whether the commonality is "an aggregate identity" or merely "how `Identity` happens to
 * spell things".
 *
 * **Second, and this is the part that is easy to miss: the naive extraction opens a type hole.** A
 * base class declaring `public function equals(self $other): bool` resolves `self` to the **base**,
 * so after extraction `$userId->equals($passwordResetRequestId)` compiles and returns a
 * meaningful-looking `false`. Today that is a `TypeError` at the moment it is written. Trading a
 * compile-time guarantee for forty saved lines — in the context that holds the credentials, where a
 * confidently wrong `false` is an authorisation answer — is a bad trade, and recovering the
 * guarantee costs an `instanceof static` check in every caller's mental model, i.e. more complexity
 * than the duplication it removes.
 *
 * So the duplication is cheap, local and non-viral: three short files that nothing else depends on,
 * each saying out loud that a user id and a challenge id are different concepts which merely happen
 * to share an encoding. See the technical plan's *Reuse vs duplication* section for the same
 * argument applied to the aggregates and the token value objects.
 */
final readonly class PasswordResetRequestId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidPasswordResetRequestId::malformed($value);
        }

        // Stored lower-cased so that two ids differing only in hex case compare equal — the
        // regex accepts both, so normalising is what makes `equals()` honest.
        return new self(mb_strtolower($value));
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
