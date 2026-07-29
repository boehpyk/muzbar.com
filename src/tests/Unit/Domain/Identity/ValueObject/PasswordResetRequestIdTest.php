<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidPasswordResetRequestId;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors `EmailVerificationRequestIdTest` (itself mirroring `UserIdTest`) — deliberately, per
 * `PasswordResetRequestId`'s own docblock: this is the *third* near-clone in the context, and the
 * duplication is a knowing choice (ADR-0009's 2026-07-29 amendment) rather than an oversight, so its
 * test suite is knowingly duplicated too.
 */
final class PasswordResetRequestIdTest extends TestCase
{
    public function testFromStringAcceptsARfc4122FormattedUuid(): void
    {
        $id = PasswordResetRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame('018f5b2a-0000-7000-8000-000000000000', $id->toString());
    }

    /**
     * The format check is deliberately version-agnostic (no UUIDv7 pinning): any RFC 4122 layout
     * passes, whatever version octet it carries.
     */
    public function testAcceptsIdsOfAnyUuidVersion(): void
    {
        $v4 = PasswordResetRequestId::fromString('550e8400-e29b-41d4-a716-446655440000');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $v4->toString());
    }

    public function testNormalisesHexCaseSoIdsDifferingOnlyByCaseAreEqual(): void
    {
        $lower = PasswordResetRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $upper = PasswordResetRequestId::fromString('018F5B2A-0000-7000-8000-000000000000');

        self::assertTrue($lower->equals($upper));
        self::assertSame($lower->toString(), $upper->toString());
    }

    public function testTwoDifferentIdsAreNotEqual(): void
    {
        $one = PasswordResetRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $other = PasswordResetRequestId::fromString('018f5b2a-0000-7000-8000-000000000001');

        self::assertFalse($one->equals($other));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIdProvider(): iterable
    {
        yield 'plain string' => ['not-a-uuid'];
        yield 'too short' => ['018f5b2a-0000-7000-8000'];
        yield 'wrong grouping' => ['018f5b2a00007000800000000000000'];
        yield 'empty string' => [''];
    }

    #[DataProvider('malformedIdProvider')]
    public function testRejectsMalformedIds(string $malformed): void
    {
        $this->expectException(InvalidPasswordResetRequestId::class);

        PasswordResetRequestId::fromString($malformed);
    }

    public function testToStringAndStringCastAgree(): void
    {
        $id = PasswordResetRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame($id->toString(), (string) $id);
    }

    /**
     * "Cross-type comparison does not compile" cannot be asserted directly — a `TypeError` at
     * compile time is not a runtime event PHPUnit can catch, and writing
     * `$userId->equals($resetRequestId)` in this file would simply fail to compile the test itself.
     * What *can* be asserted is the mechanism that produces that guarantee: `equals()`'s single
     * parameter is declared `self`, not some shared interface or base type. Reflection reports the
     * type name as the literal string `"self"` (not resolved to the class name), which is exactly
     * what keeps `$userId->equals($resetRequestId)` a `TypeError` — `UserId` and
     * `PasswordResetRequestId` each have their *own* `self`, so PHP's type checker treats them as
     * unrelated parameter types — rather than a meaningful-looking `false` that a shared base class's
     * resolved `self` would silently produce (see the class docblock's own worked example of that
     * failure mode).
     */
    public function testEqualsParameterTypeIsSelfWhichIsWhatMakesCrossTypeComparisonATypeError(): void
    {
        $method = new \ReflectionMethod(PasswordResetRequestId::class, 'equals');
        $parameters = $method->getParameters();

        self::assertCount(1, $parameters);

        $type = $parameters[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('self', $type->getName());
    }
}
