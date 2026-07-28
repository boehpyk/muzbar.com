<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidEmailVerificationRequestId;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors `UserIdTest` exactly — deliberately, per `EmailVerificationRequestId`'s own docblock: this
 * is `UserId` with two identifiers changed, and the duplication is a knowing choice (ADR-0009,
 * Consequences) rather than an oversight, so its test suite is knowingly duplicated too.
 */
final class EmailVerificationRequestIdTest extends TestCase
{
    public function testFromStringAcceptsARfc4122FormattedUuid(): void
    {
        $id = EmailVerificationRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame('018f5b2a-0000-7000-8000-000000000000', $id->toString());
    }

    /**
     * The format check is deliberately version-agnostic (no UUIDv7 pinning): any RFC 4122 layout
     * passes, whatever version octet it carries.
     */
    public function testAcceptsIdsOfAnyUuidVersion(): void
    {
        $v4 = EmailVerificationRequestId::fromString('550e8400-e29b-41d4-a716-446655440000');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $v4->toString());
    }

    public function testNormalisesHexCaseSoIdsDifferingOnlyByCaseAreEqual(): void
    {
        $lower = EmailVerificationRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $upper = EmailVerificationRequestId::fromString('018F5B2A-0000-7000-8000-000000000000');

        self::assertTrue($lower->equals($upper));
        self::assertSame($lower->toString(), $upper->toString());
    }

    public function testTwoDifferentIdsAreNotEqual(): void
    {
        $one = EmailVerificationRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $other = EmailVerificationRequestId::fromString('018f5b2a-0000-7000-8000-000000000001');

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
        $this->expectException(InvalidEmailVerificationRequestId::class);

        EmailVerificationRequestId::fromString($malformed);
    }

    public function testToStringAndStringCastAgree(): void
    {
        $id = EmailVerificationRequestId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame($id->toString(), (string) $id);
    }
}
