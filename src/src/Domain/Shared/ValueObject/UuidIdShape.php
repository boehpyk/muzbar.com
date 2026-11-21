<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

/**
 * The UUID id shape, shared by every id value object — the code, never the type.
 *
 * - **Version-agnostic.** Any RFC 4122 layout, any hex case. Pinning UUIDv7 would turn a
 *   persistence-performance choice (time-ordered keys) into a domain rule.
 * - **No `generate()`.** Minting an id means choosing a version and a library (`symfony/uid`), an
 *   Infrastructure decision owned by each repository's `nextIdentity()`.
 * - **`D` on the regex.** Without it a bare `$` also matches before a trailing "\n", so
 *   `"<uuid>\n"` would be accepted and stored with the newline.
 * - **Lower-cased on the way in**, so two ids differing only in hex case are `equals()`.
 * - **A trait, not a base class** (ADR-0009, 2026-07-29 and 2026-11-14 amendments). Inside a trait
 *   `self` resolves to the *using* class, so `CategoryId::equals(UserId)` is still a `TypeError`;
 *   in an abstract base `self` would be the base, and it would quietly return `false`.
 *   `UuidIdShapeTest` is the proof.
 *
 * Each user supplies its own exception through `malformed()`, so a caller still catches
 * `InvalidUserId`, not a generic one.
 *
 * Footgun: a `readonly class` cannot use a trait whose property is not itself declared
 * `readonly` (fatal error) — keep `private readonly` below.
 */
trait UuidIdShape
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private function __construct(
        private readonly string $value,
    ) {
    }

    abstract private static function malformed(string $value): \DomainException;

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw self::malformed($value);
        }

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
