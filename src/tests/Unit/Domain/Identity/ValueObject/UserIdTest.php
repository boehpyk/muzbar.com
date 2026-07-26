<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\InvalidUserId;
use App\Domain\Identity\ValueObject\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserIdTest extends TestCase
{
    public function testFromStringAcceptsARfc4122FormattedUuid(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame('018f5b2a-0000-7000-8000-000000000000', $id->toString());
    }

    /**
     * The format check is deliberately version-agnostic (no UUIDv7 pinning): any RFC 4122 layout
     * passes, whatever version octet it carries.
     */
    public function testAcceptsIdsOfAnyUuidVersion(): void
    {
        $v4 = UserId::fromString('550e8400-e29b-41d4-a716-446655440000');

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $v4->toString());
    }

    public function testNormalisesHexCaseSoIdsDifferingOnlyByCaseAreEqual(): void
    {
        $lower = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $upper = UserId::fromString('018F5B2A-0000-7000-8000-000000000000');

        self::assertTrue($lower->equals($upper));
        self::assertSame($lower->toString(), $upper->toString());
    }

    public function testTwoDifferentIdsAreNotEqual(): void
    {
        $one = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $other = UserId::fromString('018f5b2a-0000-7000-8000-000000000001');

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
        $this->expectException(InvalidUserId::class);

        UserId::fromString($malformed);
    }

    public function testToStringAndStringCastAgree(): void
    {
        $id = UserId::fromString('018f5b2a-0000-7000-8000-000000000000');

        self::assertSame($id->toString(), (string) $id);
    }
}
