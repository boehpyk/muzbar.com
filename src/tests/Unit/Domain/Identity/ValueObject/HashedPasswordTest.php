<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedPassword;
use App\Domain\Identity\ValueObject\HashedPassword;
use PHPUnit\Framework\TestCase;

/**
 * `HashedPassword` is deliberately opaque: it must accept whatever the Infrastructure hasher
 * produced without inspecting its shape, so the Domain never couples itself to bcrypt vs
 * Argon2id vs anything else (AC-10's "the domain never sees the algorithm" half).
 */
final class HashedPasswordTest extends TestCase
{
    public function testAcceptsAnArbitraryOpaqueStringRegardlessOfShape(): void
    {
        // Not bcrypt-shaped, not argon2-shaped — on purpose. The VO must not care.
        $hash = HashedPassword::fromString('totally-opaque-not-a-real-hash-format-42');

        self::assertSame('totally-opaque-not-a-real-hash-format-42', $hash->toString());
    }

    public function testAcceptsARealisticBcryptShapedHashToo(): void
    {
        $hash = HashedPassword::fromString('$2y$04$'.str_repeat('a', 53));

        self::assertStringStartsWith('$2y$04$', $hash->toString());
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidHashedPassword::class);

        HashedPassword::fromString('');
    }

    public function testAcceptsExactlyTheMaximumLength(): void
    {
        $hash = HashedPassword::fromString(str_repeat('a', HashedPassword::MAX_LENGTH));

        self::assertSame(HashedPassword::MAX_LENGTH, mb_strlen($hash->toString()));
    }

    public function testRejectsAStringLongerThanTheMaximumLength(): void
    {
        $this->expectException(InvalidHashedPassword::class);

        HashedPassword::fromString(str_repeat('a', HashedPassword::MAX_LENGTH + 1));
    }

    public function testTwoHashesWithTheSameValueAreEqual(): void
    {
        $one = HashedPassword::fromString('same-opaque-value');
        $other = HashedPassword::fromString('same-opaque-value');

        self::assertTrue($one->equals($other));
    }

    public function testTwoHashesWithDifferentValuesAreNotEqual(): void
    {
        $one = HashedPassword::fromString('one-opaque-value');
        $other = HashedPassword::fromString('another-opaque-value');

        self::assertFalse($one->equals($other));
    }

    /**
     * The value is never trimmed: a hash is opaque, so leading/trailing bytes are not decorative
     * whitespace the VO is entitled to strip.
     */
    public function testDoesNotTrimTheValue(): void
    {
        $hash = HashedPassword::fromString(' padded-hash ');

        self::assertSame(' padded-hash ', $hash->toString());
    }
}
