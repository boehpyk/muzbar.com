<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeCodeTest extends TestCase
{
    public function testTrimsAndLowerCases(): void
    {
        self::assertSame('string-count', AttributeCode::fromString(' String-Count ')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'single letter' => ['a'];
        yield 'digit first' => ['5-string'];
        yield '64 characters' => [str_repeat('a', 64)];
    }

    #[DataProvider('accepted')]
    public function testAcceptsValidCodes(string $value): void
    {
        self::assertSame($value, AttributeCode::fromString($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('a', 65)];
        yield 'leading hyphen' => ['-a'];
        yield 'trailing hyphen' => ['a-'];
        yield 'double hyphen' => ['a--b'];
        yield 'underscore' => ['a_b'];
        yield 'space inside' => ['a b'];
        yield 'non-ascii letter' => ['ś'];
    }

    #[DataProvider('rejected')]
    public function testRejectsInvalidCodes(string $value): void
    {
        $this->expectException(InvalidAttributeCode::class);

        AttributeCode::fromString($value);
    }

    public function testEqualityIsByNormalisedValue(): void
    {
        self::assertTrue(AttributeCode::fromString('Neck')->equals(AttributeCode::fromString('neck')));
        self::assertFalse(AttributeCode::fromString('neck')->equals(AttributeCode::fromString('body')));
    }
}
