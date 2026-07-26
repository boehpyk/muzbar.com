<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidUserId;

/**
 * The identity of a User, as a value.
 *
 * There is deliberately **no `generate()` method here.** Minting an id means choosing a UUID
 * version and an implementation (`symfony/uid`), which is a vendor decision and therefore an
 * Infrastructure one — `UserRepository::nextIdentity()` owns it. The Domain only states what a
 * valid id *looks like*, which it can do with a regex and no dependency at all.
 *
 * The format check is intentionally version-agnostic: it accepts any RFC 4122 layout. Pinning it
 * to UUIDv7 would encode a persistence-performance choice (time-ordered keys keep B-tree pages
 * from fragmenting) into a domain rule, and would reject perfectly valid ids minted by a future
 * adapter or by a fixture.
 */
final readonly class UserId
{
    private const string FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw InvalidUserId::malformed($value);
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
