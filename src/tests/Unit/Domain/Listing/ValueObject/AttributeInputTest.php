<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use PHPUnit\Framework\TestCase;

final class AttributeInputTest extends TestCase
{
    private const string A = '018f5b2a-0000-7000-8000-00000000a001';
    private const string B = '018f5b2a-0000-7000-8000-00000000a002';

    public function testEntriesKeepTheIdAndTheRawTextInOrder(): void
    {
        $entries = AttributeInput::fromArray([self::B => ' 7 ', self::A => ''])->entries();

        self::assertCount(2, $entries);
        self::assertSame(self::B, $entries[0][0]->toString());
        self::assertSame(' 7 ', $entries[0][1]);
        self::assertSame(self::A, $entries[1][0]->toString());
        self::assertSame('', $entries[1][1]);
    }

    public function testAKeyThatIsNotAnIdIsNotInForce(): void
    {
        try {
            AttributeInput::fromArray(['brand' => 'x']);
            self::fail('A malformed key was accepted.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::NotInForce, $e->reason());
            self::assertSame('brand', $e->attributeKey());
            self::assertNull($e->attributeId());
        }
    }

    public function testTwoSpellingsOfOneIdAreRefusedRatherThanOneSilentlyWinning(): void
    {
        $this->expectException(InvalidAttributeValue::class);

        AttributeInput::fromArray([self::A => '1', strtoupper(self::A) => '2']);
    }

    public function testOnlyForKeepsTheEntriesOfAttributesInTheSchema(): void
    {
        $schema = AttributeSchema::of(AttributeRule::boolean(AttributeId::fromString(self::A)));

        $kept = AttributeInput::fromArray([self::A => 'yes', self::B => '5'])->onlyFor($schema)->entries();

        self::assertCount(1, $kept);
        self::assertSame(self::A, $kept[0][0]->toString());
    }

    public function testOnlyForAnEmptySchemaKeepsNothing(): void
    {
        self::assertSame([], AttributeInput::fromArray([self::A => 'yes'])->onlyFor(AttributeSchema::none())->entries());
    }
}
