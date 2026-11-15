<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidAttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeLabelTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Body type', AttributeLabel::fromString("  Body type \n")->toString());
    }

    public function testAcceptsOneHundredMultibyteCharacters(): void
    {
        $label = str_repeat('я', 100);

        self::assertSame($label, AttributeLabel::fromString($label)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield '101 characters' => [str_repeat('a', 101)];
        yield 'tab inside' => ["Body\ttype"];
        yield 'newline inside' => ["Body\ntype"];
    }

    #[DataProvider('rejected')]
    public function testRejectsInvalidLabels(string $value): void
    {
        $this->expectException(InvalidAttributeLabel::class);

        AttributeLabel::fromString($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keys(): iterable
    {
        yield 'ascii' => ['LEFT-Handed', 'left-handed'];
        yield 'cyrillic' => ['Ёлка', 'ёлка'];
        yield 'trimmed first' => ['  Left ', 'left'];
    }

    #[DataProvider('keys')]
    public function testKeyIsTheCaseFoldedTrimmedLabel(string $label, string $key): void
    {
        self::assertSame($key, AttributeLabel::fromString($label)->key());
    }

    public function testEqualityIsCaseSensitiveOnTheLabelItself(): void
    {
        self::assertTrue(AttributeLabel::fromString('Left')->equals(AttributeLabel::fromString(' Left ')));
        self::assertFalse(AttributeLabel::fromString('Left')->equals(AttributeLabel::fromString('left')));
    }
}
