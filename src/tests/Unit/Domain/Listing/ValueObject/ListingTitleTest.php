<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidListingTitle;
use App\Domain\Listing\ValueObject\ListingTitle;
use PHPUnit\Framework\TestCase;

/** AC-3 (title). */
final class ListingTitleTest extends TestCase
{
    public function testTrimsTheValue(): void
    {
        self::assertSame('Fender Precision V', ListingTitle::fromString("  Fender Precision V \n")->toString());
    }

    public function testAcceptsTheBoundaries(): void
    {
        self::assertSame(3, mb_strlen(ListingTitle::fromString('abc')->toString()));
        self::assertSame(120, mb_strlen(ListingTitle::fromString(str_repeat('a', 120))->toString()));
    }

    public function testCountsCharactersNotBytes(): void
    {
        $title = str_repeat('ї', 120);

        self::assertSame($title, ListingTitle::fromString($title)->toString());
    }

    public function testRejectsTwoCharacters(): void
    {
        $this->expectException(InvalidListingTitle::class);

        ListingTitle::fromString('ab');
    }

    public function testRejectsTwoCharactersPaddedWithSpaces(): void
    {
        $this->expectException(InvalidListingTitle::class);

        ListingTitle::fromString('   ab   ');
    }

    public function testRejects121Characters(): void
    {
        $this->expectException(InvalidListingTitle::class);

        ListingTitle::fromString(str_repeat('a', 121));
    }

    public function testRejectsABlankTitle(): void
    {
        $this->expectException(InvalidListingTitle::class);

        ListingTitle::fromString('   ');
    }

    public function testEqualsComparesTheValue(): void
    {
        self::assertTrue(ListingTitle::fromString('Bass')->equals(ListingTitle::fromString(' Bass ')));
        self::assertFalse(ListingTitle::fromString('Bass')->equals(ListingTitle::fromString('Bassy')));
    }
}
