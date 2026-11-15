<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\CategoryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeIdTest extends TestCase
{
    public function testAcceptsAnRfc4122String(): void
    {
        self::assertSame(
            '018f5b2a-0000-7000-8000-000000000000',
            AttributeId::fromString('018f5b2a-0000-7000-8000-000000000000')->toString(),
        );
    }

    public function testNormalisesToLowerCase(): void
    {
        self::assertSame(
            'abcdef01-0000-7000-8000-00000000abcd',
            AttributeId::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->toString(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'missing hyphens' => ['018f5b2a000070008000000000000000'];
        yield 'non-hex digit' => ['018f5b2a-0000-7000-8000-00000000000g'];
        yield 'trailing newline' => ["018f5b2a-0000-7000-8000-000000000000\n"];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingElse(string $value): void
    {
        $this->expectException(InvalidAttributeId::class);

        AttributeId::fromString($value);
    }

    public function testEqualsIgnoresHexCase(): void
    {
        self::assertTrue(
            AttributeId::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')
                ->equals(AttributeId::fromString('abcdef01-0000-7000-8000-00000000abcd')),
        );
    }

    public function testEqualsRejectsADifferentIdType(): void
    {
        $mine = AttributeId::fromString('018f5b2a-0000-7000-8000-000000000000');
        $other = CategoryId::fromString('018f5b2a-0000-7000-8000-000000000000');

        $this->expectException(\TypeError::class);

        // @phpstan-ignore argument.type (the runtime TypeError is the behaviour under test)
        self::assertFalse($mine->equals($other));
    }
}
