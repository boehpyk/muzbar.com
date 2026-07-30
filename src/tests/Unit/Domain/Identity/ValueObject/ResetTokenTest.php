<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidResetToken;
use App\Domain\Identity\ValueObject\ResetToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC-10, AC-17: the token policy — exactly 43 URL-safe base64 characters — is domain knowledge
 * carried by this value object, and it is the *only* place that format is checked (the route
 * accepts any `[^/]+` segment). Mirrors `VerificationTokenTest`'s structure closely — the two
 * classes are near-clones by design (see `ResetToken`'s own docblock) — but this suite additionally
 * pins that no `__toString()` exists, because an implicit string conversion of an
 * account-takeover primitive is a strictly worse leak than one of a mere reachability proof.
 */
final class ResetTokenTest extends TestCase
{
    private const string VALID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // 43 chars, [A-Za-z0-9_-]

    public function testAcceptsARealFortyThreeCharacterBase64UrlString(): void
    {
        self::assertSame(43, \strlen(self::VALID));

        $token = ResetToken::fromString(self::VALID);

        self::assertSame(self::VALID, $token->reveal());
    }

    /**
     * A realistic token as `RandomResetTokenGenerator` would actually produce one — mixed case,
     * digits, and both of the URL-safe alphabet's non-alphanumeric characters — proving the format
     * accepts the full charset it claims to, not just a string of one repeated character.
     */
    public function testAcceptsARealisticMixedCharsetToken(): void
    {
        $realistic = 'aZ09_-'.str_repeat('x', ResetToken::LENGTH - 6);
        self::assertSame(ResetToken::LENGTH, \strlen($realistic));

        $token = ResetToken::fromString($realistic);

        self::assertSame($realistic, $token->reveal());
    }

    public function testRejectsFortyTwoCharacters(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString(str_repeat('a', 42));
    }

    public function testRejectsFortyFourCharacters(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString(str_repeat('a', 44));
    }

    public function testRejectsAPlusCharacter(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString('+'.str_repeat('a', 42));
    }

    public function testRejectsASlashCharacter(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString('/'.str_repeat('a', 42));
    }

    public function testRejectsAnEqualsPaddingCharacter(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString(str_repeat('a', 42).'=');
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString('');
    }

    /**
     * A 10 kB junk string — the route's `requirements` do no format checking at all (see the class
     * docblock: `app_reset_password_check` accepts any `[^/]+` segment on purpose), so this value
     * object is the *only* gate, and it must refuse a payload far larger than any legitimate token
     * without choking on it.
     */
    public function testRejectsATenKilobyteString(): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString(str_repeat('a', 10 * 1024));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokenProvider(): iterable
    {
        yield 'whitespace padded' => [' '.str_repeat('a', 42)];
        yield 'contains a space' => [str_repeat('a', 21).' '.str_repeat('a', 21)];
        yield 'contains a newline' => [str_repeat('a', 42)."\n"];
    }

    /**
     * The value is not trimmed: a whitespace-padded token is refused as damaged input, never
     * silently repaired. Guessing at intent is worse here than for a verification token — see the
     * class docblock — because the thing being "repaired" is an account-takeover credential.
     */
    #[DataProvider('malformedTokenProvider')]
    public function testRejectsMalformedTokensRatherThanRepairingThem(string $malformed): void
    {
        $this->expectException(InvalidResetToken::class);

        ResetToken::fromString($malformed);
    }

    /**
     * base64url distinguishes case — `a` and `A` are different characters — so a case-folded
     * variant of a valid token must be treated as a *different* token, never silently accepted as
     * the one that was actually mailed. If it were accepted, a lookup keyed on the case-sensitive
     * digest would simply miss and the attacker's guess would look like an ordinary expired link.
     */
    public function testIsCaseSensitive(): void
    {
        $lower = str_repeat('a', 43);
        $upper = str_repeat('A', 43);

        $lowerToken = ResetToken::fromString($lower);
        $upperToken = ResetToken::fromString($upper);

        self::assertNotSame($lowerToken->reveal(), $upperToken->reveal());
    }

    public function testDebugInfoMasksTheValue(): void
    {
        $token = ResetToken::fromString(self::VALID);

        self::assertSame(['value' => '***'], $token->__debugInfo());
    }

    public function testPrintROutputNeverContainsThePlaintext(): void
    {
        $token = ResetToken::fromString(self::VALID);

        $dumped = print_r($token, true);

        self::assertStringNotContainsString(self::VALID, $dumped);
    }

    public function testVarDumpOutputNeverContainsThePlaintext(): void
    {
        $token = ResetToken::fromString(self::VALID);

        ob_start();
        var_dump($token);
        $dumped = ob_get_clean();

        self::assertStringNotContainsString(self::VALID, (string) $dumped);
    }

    /**
     * An accidental string interpolation must be a `TypeError`, not a silent leak, which requires
     * the class to expose no `__toString()` at all — asserted by reflection, because "we did not
     * add a method" is otherwise invisible to review. This is the assertion the QA brief calls out
     * by name: a docblock saying "no `__toString()`" is not evidence of anything until something
     * actually fails when the method exists, which is exactly what this test does.
     */
    public function testExposesNoToStringMethod(): void
    {
        $reflection = new \ReflectionClass(ResetToken::class);

        self::assertFalse($reflection->hasMethod('__toString'));
    }
}
