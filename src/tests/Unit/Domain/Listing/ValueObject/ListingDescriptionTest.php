<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidListingDescription;
use App\Domain\Listing\ValueObject\ListingDescription;
use PHPUnit\Framework\TestCase;

/** AC-3 (description). */
final class ListingDescriptionTest extends TestCase
{
    public function testTrimsTheValue(): void
    {
        self::assertSame('Mint.', ListingDescription::fromString("  Mint.\n")->toString());
    }

    public function testAcceptsAnEmptyDescription(): void
    {
        self::assertSame('', ListingDescription::fromString('')->toString());
    }

    public function testKeepsInnerLineBreaks(): void
    {
        self::assertSame("a\nb", ListingDescription::fromString("a\nb")->toString());
    }

    public function testAccepts5000Characters(): void
    {
        self::assertSame(5000, mb_strlen(ListingDescription::fromString(str_repeat('ї', 5000))->toString()));
    }

    public function testRejects5001Characters(): void
    {
        $this->expectException(InvalidListingDescription::class);

        ListingDescription::fromString(str_repeat('a', 5001));
    }

    public function testEqualsComparesTheValue(): void
    {
        self::assertTrue(ListingDescription::fromString('x')->equals(ListingDescription::fromString(' x')));
        self::assertFalse(ListingDescription::fromString('x')->equals(ListingDescription::fromString('y')));
    }
}
