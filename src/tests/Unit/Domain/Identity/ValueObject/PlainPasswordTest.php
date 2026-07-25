<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\WeakPassword;
use App\Domain\Identity\ValueObject\PlainPassword;
use PHPUnit\Framework\TestCase;

/**
 * AC-5 (VO layer): the password policy is domain knowledge carried by this value object, not a
 * form annotation. AC-10: the plaintext must never be printable or interpolable by accident.
 */
final class PlainPasswordTest extends TestCase
{
    public function testRejectsAPasswordShorterThanTwelveCharacters(): void
    {
        $this->expectException(WeakPassword::class);
        $this->expectExceptionMessage('at least 12 characters');

        PlainPassword::fromString(str_repeat('a', PlainPassword::MIN_LENGTH - 1));
    }

    public function testAcceptsExactlyTheMinimumLength(): void
    {
        $password = PlainPassword::fromString(str_repeat('a', PlainPassword::MIN_LENGTH));

        self::assertSame(str_repeat('a', PlainPassword::MIN_LENGTH), $password->reveal());
    }

    public function testAcceptsExactlyTheMaximumLength(): void
    {
        $password = PlainPassword::fromString(str_repeat('a', PlainPassword::MAX_LENGTH));

        self::assertSame(PlainPassword::MAX_LENGTH, \strlen($password->reveal()));
    }

    public function testRejectsAPasswordLongerThanTheMaximumByteLength(): void
    {
        $this->expectException(WeakPassword::class);
        $this->expectExceptionMessage('may not exceed 4096 bytes');

        PlainPassword::fromString(str_repeat('a', PlainPassword::MAX_LENGTH + 1));
    }

    /**
     * The minimum is measured in *characters*, not bytes: a multi-byte passphrase should be
     * judged by what the human typed, not by how UTF-8 happens to encode it. Twelve Cyrillic
     * characters are well under the byte-oriented naive reading of the bound (24 bytes) but must
     * still pass, because 12 *characters* were typed.
     */
    public function testMeasuresTheMinimumInCharactersNotBytes(): void
    {
        $cyrillic = str_repeat('щ', PlainPassword::MIN_LENGTH); // 2 bytes/char in UTF-8

        $password = PlainPassword::fromString($cyrillic);

        self::assertSame($cyrillic, $password->reveal());
    }

    public function testRevealReturnsExactlyWhatWasGiven(): void
    {
        $password = PlainPassword::fromString('correct horse battery staple');

        self::assertSame('correct horse battery staple', $password->reveal());
    }

    /**
     * AC-10: `var_dump`/`print_r` must never show the plaintext.
     */
    public function testDebugInfoMasksTheValue(): void
    {
        $password = PlainPassword::fromString('correct horse battery staple');

        self::assertSame(['value' => '***'], $password->__debugInfo());
    }

    /**
     * AC-10 names `print_r` explicitly. `print_r()` (like `var_dump()`) consults `__debugInfo()`,
     * so both must show the mask rather than the secret.
     */
    public function testPrintROutputNeverContainsThePlaintext(): void
    {
        $password = PlainPassword::fromString('super-secret-value-12345');

        $dumped = print_r($password, true);

        self::assertStringNotContainsString('super-secret-value-12345', $dumped);
    }

    public function testVarDumpOutputNeverContainsThePlaintext(): void
    {
        $password = PlainPassword::fromString('another-super-secret-999');

        ob_start();
        var_dump($password);
        $dumped = ob_get_clean();

        self::assertStringNotContainsString('another-super-secret-999', (string) $dumped);
    }

    /**
     * AC-10: an accidental string interpolation (`"password: $password"`) must be a TypeError,
     * not a silent leak — which requires the class to expose no `__toString()` at all. Asserted
     * by reflection so a future accidental addition of the method fails this test immediately.
     */
    public function testExposesNoToStringMethod(): void
    {
        $reflection = new \ReflectionClass(PlainPassword::class);

        self::assertFalse($reflection->hasMethod('__toString'));
    }
}
