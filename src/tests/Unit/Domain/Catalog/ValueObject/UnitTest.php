<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidUnit;
use App\Domain\Catalog\ValueObject\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    public function testAcceptsShortLabels(): void
    {
        self::assertSame('mm', Unit::fromString('mm')->toString());
        self::assertSame('″', Unit::fromString('″')->toString());
    }

    public function testAcceptsSixteenCharacters(): void
    {
        self::assertSame(16, mb_strlen(Unit::fromString(str_repeat('м', 16))->toString()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield '17 characters' => [str_repeat('a', 17)];
        yield 'newline inside' => ["m\nm"];
    }

    #[DataProvider('rejected')]
    public function testRejectsInvalidUnits(string $value): void
    {
        $this->expectException(InvalidUnit::class);

        Unit::fromString($value);
    }

    public function testEqualityIsByValue(): void
    {
        self::assertTrue(Unit::fromString('mm')->equals(Unit::fromString(' mm ')));
        self::assertFalse(Unit::fromString('mm')->equals(Unit::fromString('kg')));
    }
}
