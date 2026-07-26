<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\ValueObject\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure Domain unit tests — no kernel boot. Covers AC-5's "correctness" layer for email shape
 * indirectly (registration reuses this VO) and AC-7 directly: rejection of malformed and
 * over-long addresses at the value-object boundary.
 */
final class EmailTest extends TestCase
{
    /**
     * AC-7 (VO layer): whitespace is trimmed and the whole address — local part included — is
     * lower-cased, per the VO's documented decision to fold local-part case too.
     */
    public function testFromStringTrimsWhitespaceAndLowerCasesTheWholeAddress(): void
    {
        $email = Email::fromString(' Max@Example.COM ');

        self::assertSame('max@example.com', $email->toString());
    }

    /**
     * AC-4's underlying rule: two addresses differing only by case/whitespace normalise to the
     * same value and therefore compare equal.
     */
    public function testTwoAddressesDifferingOnlyByCaseAndWhitespaceAreEqual(): void
    {
        $normalised = Email::fromString('max@example.com');
        $mixedCase = Email::fromString('Max@Example.COM ');

        self::assertTrue($normalised->equals($mixedCase));
        self::assertTrue($mixedCase->equals($normalised));
    }

    public function testTwoDifferentAddressesAreNotEqual(): void
    {
        $one = Email::fromString('max@example.com');
        $other = Email::fromString('alex@example.com');

        self::assertFalse($one->equals($other));
    }

    /**
     * AC-7 (VO layer): a syntactically invalid address is rejected by `FILTER_VALIDATE_EMAIL`.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedAddressProvider(): iterable
    {
        yield 'no @ or domain' => ['not-an-email'];
        yield 'no domain after @' => ['a@'];
        yield 'no local part' => ['@b.com'];
    }

    #[DataProvider('malformedAddressProvider')]
    public function testRejectsSyntacticallyMalformedAddresses(string $malformed): void
    {
        $this->expectException(InvalidEmail::class);

        Email::fromString($malformed);
    }

    /**
     * AC-7 (VO layer). GOTCHA: `filter_var` enforces RFC local-part limits (64 chars) before this
     * VO's own 180-char check ever runs, so a naive `str_repeat('a', 180).'@example.com'` throws
     * `InvalidEmail::malformed`, not `tooLong` — it never reaches the length check at all. To
     * exercise the `tooLong` branch specifically, the shape below keeps every RFC-mandated
     * component (local part, each DNS label) within its own limit while pushing the *total*
     * address past 180 characters.
     */
    public function testRejectsAnAddressLongerThan180CharactersViaTheLengthCheck(): void
    {
        $shape = str_repeat('a', 60).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.com';

        // Sanity-check the fixture itself: if this stops being > 180 chars the test below would
        // pass for the wrong reason (or not exercise the length branch at all).
        self::assertGreaterThan(Email::MAX_LENGTH, mb_strlen($shape));

        $this->expectException(InvalidEmail::class);
        $this->expectExceptionMessage('may not exceed 180 characters');

        Email::fromString($shape);
    }

    /**
     * The naive "just repeat 'a' 180 times" shape is documented here precisely because it throws
     * for the *other* reason. Asserting it explicitly keeps that gotcha from being silently
     * "fixed" by a future refactor of `Email` without anyone noticing the test coverage moved.
     */
    public function testAnOverlyLongLocalPartIsRejectedAsMalformedNotAsTooLong(): void
    {
        $this->expectException(InvalidEmail::class);
        $this->expectExceptionMessage('is not a valid email address');

        Email::fromString(str_repeat('a', 180).'@example.com');
    }

    public function testToStringAndStringCastAgree(): void
    {
        $email = Email::fromString('max@example.com');

        self::assertSame($email->toString(), (string) $email);
    }
}
