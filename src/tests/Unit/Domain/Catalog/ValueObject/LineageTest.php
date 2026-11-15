<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidLineage;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\Lineage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class LineageTest extends TestCase
{
    private static function id(): CategoryId
    {
        return CategoryId::fromString(Uuid::v7()->toRfc4122());
    }

    public function testRootLineageHasDepthZeroAndNoLastElement(): void
    {
        $root = Lineage::ofRoot();

        self::assertSame(0, $root->depth());
        self::assertNull($root->last());
    }

    public function testDepthAndLastOfARootDownList(): void
    {
        [$a, $b, $c] = [self::id(), self::id(), self::id()];

        $lineage = Lineage::fromRootDown([$a, $b, $c]);

        self::assertSame(3, $lineage->depth());
        self::assertNotNull($lineage->last());
        self::assertTrue($lineage->last()->equals($c));
    }

    public function testContainsFindsMembersOnly(): void
    {
        [$a, $b, $c] = [self::id(), self::id(), self::id()];
        $lineage = Lineage::fromRootDown([$a, $b]);

        self::assertTrue($lineage->contains($b));
        self::assertFalse($lineage->contains($c));
    }

    public function testRejectsADuplicateId(): void
    {
        $a = self::id();

        $this->expectException(InvalidLineage::class);

        Lineage::fromRootDown([$a, self::id(), CategoryId::fromString($a->toString())]);
    }

    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(InvalidLineage::class);

        Lineage::fromRootDown([]);
    }
}
