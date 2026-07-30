<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidHashedResetToken;
use App\Domain\Identity\ValueObject\HashedResetToken;
use PHPUnit\Framework\TestCase;

/**
 * AC-27, AC-35: `HashedResetToken` is deliberately opaque — it must accept whatever digest the
 * `ResetTokenGenerator` adapter produced without inspecting its shape, so the Domain never learns
 * which algorithm produced it (the class's own docblock, ADR-0011 decision 2). `equals()` is the
 * actual redemption gate: only the token whose digest matches may burn a request (invariant I-19).
 *
 * ON THE `hash_equals` / TIMING CLAIM. `equals()`'s docblock says the comparison is constant-time.
 * What this suite can honestly assert is the *result*: an equal-length, one-character-off near-miss
 * still compares false, which is the behaviour that distinguishes `hash_equals` from a naive `===`
 * (in this specific case `===` would actually give the same *answer*, since it does not
 * short-circuit on equal-length differing strings in a way PHP exposes to a black-box test — the
 * distinguishing property of `hash_equals` is its execution *time*, not its return value, and a unit
 * test cannot measure wall-clock time without being flaky or unfalsifiable, per the technical plan's
 * explicit ban on timing assertions). This suite therefore proves the comparison gives the right
 * answer on a near-miss; it does **not** and cannot prove the comparison runs in constant time, and
 * does not claim to.
 */
final class HashedResetTokenTest extends TestCase
{
    /**
     * Deliberately not SHA-256-shaped (64 lower-case hex characters) — this is the assertion that
     * proves the Domain does not know the algorithm the digest came from. Accepting a value that
     * looks nothing like a real digest is what makes `ResetTokenGenerator` free to change algorithms
     * without touching this class.
     */
    public function testAcceptsAnArbitraryOpaqueStringRegardlessOfShape(): void
    {
        $hash = HashedResetToken::fromString('totally-opaque-not-a-real-digest-format');

        self::assertSame('totally-opaque-not-a-real-digest-format', $hash->toString());
    }

    public function testAcceptsARealisticSha256HexDigestToo(): void
    {
        $sha256Shaped = hash('sha256', 'anything');

        $hash = HashedResetToken::fromString($sha256Shaped);

        self::assertSame($sha256Shaped, $hash->toString());
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidHashedResetToken::class);

        HashedResetToken::fromString('');
    }

    public function testAcceptsExactlyTheMaximumLength(): void
    {
        $hash = HashedResetToken::fromString(str_repeat('a', HashedResetToken::MAX_LENGTH));

        self::assertSame(HashedResetToken::MAX_LENGTH, mb_strlen($hash->toString()));
    }

    public function testRejectsAStringLongerThanTheMaximumLength(): void
    {
        $this->expectException(InvalidHashedResetToken::class);

        HashedResetToken::fromString(str_repeat('a', HashedResetToken::MAX_LENGTH + 1));
    }

    public function testEqualsIsTrueForIdenticalValues(): void
    {
        $one = HashedResetToken::fromString('same-opaque-digest-value');
        $other = HashedResetToken::fromString('same-opaque-digest-value');

        self::assertTrue($one->equals($other));
    }

    /**
     * AC-27: a one-character difference is enough to refuse a match — the exact bound the
     * acceptance criterion names. Both values have the same length, which is what makes this the
     * "near-miss" case the class docblock's `hash_equals` argument is actually about (see this
     * class's own docblock for what that argument does and does not let us assert).
     */
    public function testEqualsIsFalseForAOneCharacterDifference(): void
    {
        $one = HashedResetToken::fromString('same-opaque-digest-valuE');
        $other = HashedResetToken::fromString('same-opaque-digest-value');

        self::assertFalse($one->equals($other));
    }

    public function testEqualsIsFalseForCompletelyDifferentValues(): void
    {
        $one = HashedResetToken::fromString('one-digest-entirely');
        $other = HashedResetToken::fromString('a-totally-different-digest');

        self::assertFalse($one->equals($other));
    }

    /**
     * The value is never trimmed: a digest is opaque, so leading/trailing bytes are not decorative
     * whitespace this VO is entitled to strip.
     */
    public function testDoesNotTrimTheValue(): void
    {
        $hash = HashedResetToken::fromString(' padded-digest ');

        self::assertSame(' padded-digest ', $hash->toString());
    }
}
