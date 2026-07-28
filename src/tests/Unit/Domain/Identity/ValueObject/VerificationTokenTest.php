<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidVerificationToken;
use App\Domain\Identity\ValueObject\VerificationToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC-2, AC-12: the token policy — exactly 43 URL-safe base64 characters — is domain knowledge
 * carried by this value object, and the plaintext must never be printable, interpolable or leaked
 * through `var_dump`/`print_r`. Mirrors `PlainPasswordTest`'s three secrecy guarantees exactly, per
 * the technical plan's Test plan section.
 */
final class VerificationTokenTest extends TestCase
{
    private const string VALID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // 43 chars, [A-Za-z0-9_-]

    public function testAcceptsARealFortyThreeCharacterBase64UrlString(): void
    {
        self::assertSame(43, \strlen(self::VALID));

        $token = VerificationToken::fromString(self::VALID);

        self::assertSame(self::VALID, $token->reveal());
    }

    /**
     * A realistic token as `RandomVerificationTokenGenerator` would actually produce one — mixed
     * case, digits, and both of the URL-safe alphabet's non-alphanumeric characters — proving the
     * format accepts the full charset it claims to, not just a string of one repeated character.
     */
    public function testAcceptsARealisticMixedCharsetToken(): void
    {
        $realistic = 'aZ09_-'.str_repeat('x', VerificationToken::LENGTH - 6);
        self::assertSame(VerificationToken::LENGTH, \strlen($realistic));

        $token = VerificationToken::fromString($realistic);

        self::assertSame($realistic, $token->reveal());
    }

    public function testRejectsFortyTwoCharacters(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString(str_repeat('a', 42));
    }

    public function testRejectsFortyFourCharacters(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString(str_repeat('a', 44));
    }

    public function testRejectsAPlusCharacter(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString('+'.str_repeat('a', 42));
    }

    public function testRejectsASlashCharacter(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString('/'.str_repeat('a', 42));
    }

    public function testRejectsAnEqualsPaddingCharacter(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString(str_repeat('a', 42).'=');
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString('');
    }

    /**
     * A 10 kB junk string — the route's `requirements` regex is the cheap first gate in production,
     * but the value object is the rule, and it must refuse a payload far larger than any legitimate
     * token without choking on it.
     */
    public function testRejectsATenKilobyteString(): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString(str_repeat('a', 10 * 1024));
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

    #[DataProvider('malformedTokenProvider')]
    public function testRejectsMalformedTokens(string $malformed): void
    {
        $this->expectException(InvalidVerificationToken::class);

        VerificationToken::fromString($malformed);
    }

    /**
     * base64url distinguishes case — `a` and `A` are different characters — so lower-casing or
     * otherwise normalising the value would silently accept a different token from the one that was
     * actually mailed. There is deliberately no case-folding to test for; this proves two
     * differently-cased strings are simply two different tokens.
     */
    public function testIsCaseSensitive(): void
    {
        $lower = str_repeat('a', 43);
        $upper = str_repeat('A', 43);

        $lowerToken = VerificationToken::fromString($lower);
        $upperToken = VerificationToken::fromString($upper);

        self::assertNotSame($lowerToken->reveal(), $upperToken->reveal());
    }

    public function testDebugInfoMasksTheValue(): void
    {
        $token = VerificationToken::fromString(self::VALID);

        self::assertSame(['value' => '***'], $token->__debugInfo());
    }

    public function testPrintROutputNeverContainsThePlaintext(): void
    {
        $token = VerificationToken::fromString(self::VALID);

        $dumped = print_r($token, true);

        self::assertStringNotContainsString(self::VALID, $dumped);
    }

    public function testVarDumpOutputNeverContainsThePlaintext(): void
    {
        $token = VerificationToken::fromString(self::VALID);

        ob_start();
        var_dump($token);
        $dumped = ob_get_clean();

        self::assertStringNotContainsString(self::VALID, (string) $dumped);
    }

    /**
     * AC-2: an accidental string interpolation must be a `TypeError`, not a silent leak, which
     * requires the class to expose no `__toString()` at all — asserted by reflection so a future
     * accidental addition of the method fails this test immediately, exactly as `PlainPasswordTest`
     * asserts for `PlainPassword`.
     */
    public function testExposesNoToStringMethod(): void
    {
        $reflection = new \ReflectionClass(VerificationToken::class);

        self::assertFalse($reflection->hasMethod('__toString'));
    }
}
