<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedVerificationToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use PHPUnit\Framework\TestCase;

/**
 * AC-31: `HashedVerificationToken` is deliberately opaque — it must accept whatever digest the
 * Infrastructure adapter produced without inspecting its shape, so the Domain never learns which
 * algorithm produced it (ADR-0009, decision 2). Constant-time equality is what actually enforces
 * "only the matching token may redeem a request".
 */
final class HashedVerificationTokenTest extends TestCase
{
    public function testAcceptsAnArbitraryOpaqueStringRegardlessOfShape(): void
    {
        // Not SHA-256-shaped (64 lower-case hex characters) — on purpose. The VO must not care.
        $hash = HashedVerificationToken::fromString('totally-opaque-not-a-real-digest-format');

        self::assertSame('totally-opaque-not-a-real-digest-format', $hash->toString());
    }

    public function testAcceptsARealisticSha256HexDigestToo(): void
    {
        $sha256Shaped = hash('sha256', 'anything');

        $hash = HashedVerificationToken::fromString($sha256Shaped);

        self::assertSame($sha256Shaped, $hash->toString());
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidHashedVerificationToken::class);

        HashedVerificationToken::fromString('');
    }

    public function testAcceptsExactlyTheMaximumLength(): void
    {
        $hash = HashedVerificationToken::fromString(str_repeat('a', HashedVerificationToken::MAX_LENGTH));

        self::assertSame(HashedVerificationToken::MAX_LENGTH, mb_strlen($hash->toString()));
    }

    public function testRejectsAStringLongerThanTheMaximumLength(): void
    {
        $this->expectException(InvalidHashedVerificationToken::class);

        HashedVerificationToken::fromString(str_repeat('a', HashedVerificationToken::MAX_LENGTH + 1));
    }

    public function testEqualsIsTrueForIdenticalValues(): void
    {
        $one = HashedVerificationToken::fromString('same-opaque-digest-value');
        $other = HashedVerificationToken::fromString('same-opaque-digest-value');

        self::assertTrue($one->equals($other));
    }

    /**
     * AC-31: a one-character difference is enough to refuse a match — the exact bound the
     * acceptance criterion names, rather than two obviously unrelated strings.
     */
    public function testEqualsIsFalseForAOneCharacterDifference(): void
    {
        $one = HashedVerificationToken::fromString('same-opaque-digest-valuE');
        $other = HashedVerificationToken::fromString('same-opaque-digest-value');

        self::assertFalse($one->equals($other));
    }

    public function testEqualsIsFalseForCompletelyDifferentValues(): void
    {
        $one = HashedVerificationToken::fromString('one-digest-entirely');
        $other = HashedVerificationToken::fromString('a-totally-different-digest');

        self::assertFalse($one->equals($other));
    }

    /**
     * The value is never trimmed: a digest is opaque, so leading/trailing bytes are not decorative
     * whitespace the VO is entitled to strip — mirrors `HashedPasswordTest`'s identical assertion.
     */
    public function testDoesNotTrimTheValue(): void
    {
        $hash = HashedVerificationToken::fromString(' padded-digest ');

        self::assertSame(' padded-digest ', $hash->toString());
    }
}
