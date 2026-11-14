<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategorySlug;
use App\Domain\Catalog\ValueObject\CategorySlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorySlugTest extends TestCase
{
    public function testTrimsAndLowerCases(): void
    {
        self::assertSame('bass-guitars', CategorySlug::fromString(' Bass-Guitars ')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'digit first' => ['5-string'];
        yield 'single letter' => ['a'];
        yield '64 characters' => [str_repeat('a', 64)];
    }

    #[DataProvider('accepted')]
    public function testAcceptsValidSlugs(string $value): void
    {
        self::assertSame($value, CategorySlug::fromString($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('a', 65)];
        yield 'leading hyphen' => ['-bass'];
        yield 'trailing hyphen' => ['bass-'];
        yield 'double hyphen' => ['bass--guitars'];
        yield 'underscore' => ['bass_guitars'];
        yield 'space inside' => ['bass guitars'];
        yield 'non-ascii letter' => ['basś'];
        yield 'slash' => ['/'];
    }

    #[DataProvider('rejected')]
    public function testRejectsInvalidSlugs(string $value): void
    {
        $this->expectException(InvalidCategorySlug::class);

        CategorySlug::fromString($value);
    }

    public function testEqualityIsByNormalisedValue(): void
    {
        self::assertTrue(CategorySlug::fromString('Electric')->equals(CategorySlug::fromString('electric')));
        self::assertFalse(CategorySlug::fromString('electric')->equals(CategorySlug::fromString('acoustic')));
    }
}
