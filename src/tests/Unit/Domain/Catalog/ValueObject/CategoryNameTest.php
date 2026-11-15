<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidCategoryName;
use App\Domain\Catalog\ValueObject\CategoryName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryNameTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Bass Guitars', CategoryName::fromString("  Bass Guitars\t ")->toString());
    }

    public function testAcceptsOneHundredMultibyteCharacters(): void
    {
        $name = str_repeat('я', 100);

        self::assertSame($name, CategoryName::fromString($name)->toString());
    }

    public function testAcceptsASingleCharacter(): void
    {
        self::assertSame('a', CategoryName::fromString('a')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t "];
        yield '101 characters' => [str_repeat('a', 101)];
        yield '101 multibyte characters' => [str_repeat('я', 101)];
        yield 'line feed inside' => ["Bass\nGuitars"];
        yield 'tab inside' => ["Bass\tGuitars"];
        yield 'nul byte' => ["Bass\0Guitars"];
    }

    #[DataProvider('rejected')]
    public function testRejectsInvalidNames(string $value): void
    {
        $this->expectException(InvalidCategoryName::class);

        CategoryName::fromString($value);
    }

    public function testEqualityIsByValue(): void
    {
        self::assertTrue(CategoryName::fromString(' A ')->equals(CategoryName::fromString('A')));
        self::assertFalse(CategoryName::fromString('A')->equals(CategoryName::fromString('a')));
    }
}
