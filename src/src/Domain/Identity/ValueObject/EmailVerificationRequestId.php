<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidEmailVerificationRequestId;

/**
 * The identity of an EmailVerificationRequest, as a value.
 *
 * There is deliberately **no `generate()` method here.** Minting an id means choosing a UUID version
 * and an implementation (`symfony/uid`), which is a vendor decision and therefore an Infrastructure
 * one — `EmailVerificationRequestRepository::nextIdentity()` owns it. The Domain only states what a
 * valid id *looks like*, which it can do with a regex and no dependency at all.
 *
 * The format check is intentionally version-agnostic: it accepts any RFC 4122 layout. Pinning it to
 * UUIDv7 would encode a persistence-performance choice (time-ordered keys keep B-tree pages from
 * fragmenting) into a domain rule, and would reject perfectly valid ids minted by a future adapter
 * or by a fixture.
 *
 * ON THE NEAR-DUPLICATION OF `UserId` — this is a knowing choice, not an oversight (ADR-0009,
 * *Consequences*). Line for line this class is `UserId` with two identifiers changed, and the reflex
 * is to extract a `Domain/Shared/ValueObject/Uuid` base right now. We are not doing that yet: **two
 * is a coincidence, three is a pattern.** An abstraction derived from two examples tends to fit
 * neither — it would have to guess whether the shared part is "a UUID", "an aggregate identity" or
 * "a string with a format", and each guess produces a different and mostly wrong base class. The
 * third example arrives with `Catalog`, and by then the shape of what is actually common will be an
 * observation instead of a prediction. Until then, the duplication is cheap, local, and honest about
 * the fact that a user id and a challenge id are different concepts that merely happen to share an
 * encoding.
 */
final readonly class EmailVerificationRequestId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidEmailVerificationRequestId::malformed($value);
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
