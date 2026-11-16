<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use PHPUnit\Framework\TestCase;

final class AttributeCodesTest extends TestCase
{
    private static function c(string $value): AttributeCode
    {
        return AttributeCode::fromString($value);
    }

    /**
     * @return list<string>
     */
    private static function strings(AttributeCodes $codes): array
    {
        return array_map(static fn (AttributeCode $c): string => $c->toString(), $codes->toList());
    }

    public function testOfSortsAndDeDuplicates(): void
    {
        self::assertSame(['a', 'b'], self::strings(AttributeCodes::of(self::c('b'), self::c('a'), self::c('b'))));
    }

    public function testNoneIsEmpty(): void
    {
        self::assertSame([], AttributeCodes::none()->toList());
    }

    public function testContainsComparesByValue(): void
    {
        $codes = AttributeCodes::of(self::c('a'), self::c('b'));

        self::assertTrue($codes->contains(self::c('b')));
        self::assertFalse($codes->contains(self::c('c')));
        self::assertFalse(AttributeCodes::none()->contains(self::c('a')));
    }

    public function testFirstSharedWithReturnsTheAlphabeticallyFirstSharedCode(): void
    {
        $left = AttributeCodes::of(self::c('a'), self::c('c'), self::c('d'));
        $right = AttributeCodes::of(self::c('d'), self::c('c'));

        self::assertSame('c', $left->firstSharedWith($right)?->toString());
        self::assertSame('c', $right->firstSharedWith($left)?->toString());
    }

    public function testFirstSharedWithIsNullForDisjointSets(): void
    {
        self::assertNull(AttributeCodes::of(self::c('a'))->firstSharedWith(AttributeCodes::of(self::c('b'))));
    }

    public function testFirstSharedWithIsNullWhenEitherSideIsEmpty(): void
    {
        $some = AttributeCodes::of(self::c('a'));

        self::assertNull($some->firstSharedWith(AttributeCodes::none()));
        self::assertNull(AttributeCodes::none()->firstSharedWith($some));
        self::assertNull(AttributeCodes::none()->firstSharedWith(AttributeCodes::none()));
    }
}
