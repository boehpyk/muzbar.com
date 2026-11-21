<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\AttributeValue;
use App\Domain\Listing\ValueObject\AttributeValues;
use PHPUnit\Framework\TestCase;

/** listing-attribute-values AC-2 (the L-8 half); listing-schema-drift AC-6 (the fact helpers). */
final class AttributeValuesTest extends TestCase
{
    private const string A = '018f5b2a-0000-7000-8000-00000000a001';
    private const string B = '018f5b2a-0000-7000-8000-00000000a002';
    private const string C = '018f5b2a-0000-7000-8000-00000000a003';

    private function number(string $id, int $n): AttributeValue
    {
        return AttributeValue::ofNumber(AttributeId::fromString($id), $n);
    }

    public function testNoneIsEmpty(): void
    {
        self::assertTrue(AttributeValues::none()->isEmpty());
        self::assertSame([], AttributeValues::none()->toList());
    }

    public function testTwoValuesForOneAttributeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AttributeValues::of($this->number(self::A, 1), $this->number(self::A, 2));
    }

    public function testTwoValuesForOneAttributeAreRefusedEvenWhenOnlyTheHexCaseDiffers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AttributeValues::of($this->number(self::A, 1), $this->number(strtoupper(self::A), 1));
    }

    public function testForFindsTheValueOfThatAttributeOnly(): void
    {
        $values = AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 2));

        self::assertSame(2, $values->for(AttributeId::fromString(self::B))?->number());
        self::assertNull($values->for(AttributeId::fromString(self::C)));
        self::assertFalse($values->isEmpty());
    }

    public function testToListIsSortedByAttributeIdWhateverTheInputOrder(): void
    {
        $values = AttributeValues::of($this->number(self::C, 3), $this->number(self::A, 1), $this->number(self::B, 2));

        self::assertSame([1, 2, 3], array_map(static fn (AttributeValue $v): ?int => $v->number(), $values->toList()));
    }

    public function testEqualsIsOrderIndependent(): void
    {
        $one = AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 2));
        $other = AttributeValues::of($this->number(self::B, 2), $this->number(self::A, 1));

        self::assertTrue($one->equals($other));
        self::assertTrue($other->equals($one));
    }

    public function testEqualsSeesADifferentPayloadAndADifferentSet(): void
    {
        $base = AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 2));

        self::assertFalse($base->equals(AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 3))));
        self::assertFalse($base->equals(AttributeValues::of($this->number(self::A, 1))));
        self::assertFalse($base->equals(AttributeValues::none()));
        self::assertFalse(AttributeValues::none()->equals($base));
        self::assertTrue(AttributeValues::none()->equals(AttributeValues::none()));
    }

    private function schemaOfAAndB(): AttributeSchema
    {
        return AttributeSchema::of(
            AttributeRule::number(AttributeId::fromString(self::A), null, null, false),
            AttributeRule::number(AttributeId::fromString(self::B), null, null, false),
        );
    }

    public function testOnlyForKeepsTheInForceValuesAndNotInKeepsTheRest(): void
    {
        $values = AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 2), $this->number(self::C, 3));
        $schema = $this->schemaOfAAndB();

        self::assertSame([1, 2], array_map(static fn (AttributeValue $v): ?int => $v->number(), $values->onlyFor($schema)->toList()));
        self::assertSame([3], array_map(static fn (AttributeValue $v): ?int => $v->number(), $values->notIn($schema)->toList()));
    }

    public function testOnlyForAndNotInPartitionTheOriginalSet(): void
    {
        $values = AttributeValues::of($this->number(self::A, 1), $this->number(self::B, 2), $this->number(self::C, 3));
        $schema = $this->schemaOfAAndB();
        $in = $values->onlyFor($schema);
        $out = $values->notIn($schema);

        self::assertTrue($values->equals(AttributeValues::of(...$in->toList(), ...$out->toList())));

        foreach ($in->toList() as $value) {
            self::assertNull($out->for($value->attributeId()));
        }
    }

    public function testBothHelpersOnNoneAreNone(): void
    {
        self::assertTrue(AttributeValues::none()->onlyFor($this->schemaOfAAndB())->isEmpty());
        self::assertTrue(AttributeValues::none()->notIn($this->schemaOfAAndB())->isEmpty());
    }

    public function testAnEmptySchemaMakesEveryValueDormant(): void
    {
        $values = AttributeValues::of($this->number(self::A, 1));

        self::assertTrue($values->onlyFor(AttributeSchema::of())->isEmpty());
        self::assertTrue($values->notIn(AttributeSchema::of())->equals($values));
    }
}
