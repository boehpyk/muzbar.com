<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeInput;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeRule;
use App\Domain\Listing\ValueObject\AttributeSchema;
use App\Domain\Listing\ValueObject\AttributeValue;
use App\Domain\Listing\ValueObject\AttributeValues;
use PHPUnit\Framework\TestCase;

/** AC-2. */
final class AttributeSchemaTest extends TestCase
{
    private const string BRAND = '018f5b2a-0000-7000-8000-00000000a001';
    private const string STRINGS = '018f5b2a-0000-7000-8000-00000000a002';
    private const string SERIAL = '018f5b2a-0000-7000-8000-00000000a003';
    private const string ELSEWHERE = '018f5b2a-0000-7000-8000-00000000a009';
    private const string FENDER = '018f5b2a-0000-7000-8000-00000000b001';

    private function schema(): AttributeSchema
    {
        return AttributeSchema::of(
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER)], false),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), 4, 12, false),
            AttributeRule::text(AttributeId::fromString(self::SERIAL), false),
        );
    }

    public function testReadsEveryInputAgainstItsOwnRule(): void
    {
        $values = $this->schema()->read(AttributeInput::fromArray([
            self::BRAND => self::FENDER,
            self::STRINGS => '6',
            self::SERIAL => ' SN-1 ',
        ]));

        self::assertSame(self::FENDER, $values->for(AttributeId::fromString(self::BRAND))?->optionId()?->toString());
        self::assertSame(6, $values->for(AttributeId::fromString(self::STRINGS))?->number());
        self::assertSame('SN-1', $values->for(AttributeId::fromString(self::SERIAL))?->text());
    }

    public function testEmptyInputsAreSkippedNotStoredAsEmptyValues(): void
    {
        $values = $this->schema()->read(AttributeInput::fromArray([self::BRAND => '', self::STRINGS => '8', self::SERIAL => '  ']));

        self::assertCount(1, $values->toList());
        self::assertSame(8, $values->for(AttributeId::fromString(self::STRINGS))?->number());
    }

    public function testNoInputReadsAsNoValues(): void
    {
        self::assertTrue($this->schema()->read(AttributeInput::fromArray([]))->isEmpty());
    }

    public function testAnInputForAnAttributeNotInTheSchemaIsNotInForceEvenWhenEmpty(): void
    {
        try {
            $this->schema()->read(AttributeInput::fromArray([self::ELSEWHERE => '']));
            self::fail('An attribute outside the schema was read.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::NotInForce, $e->reason());
            self::assertSame(self::ELSEWHERE, $e->attributeId()?->toString());
        }
    }

    public function testARuleRefusalPropagatesWithItsAttribute(): void
    {
        try {
            $this->schema()->read(AttributeInput::fromArray([self::STRINGS => '13']));
            self::fail('13 was accepted for 4…12.');
        } catch (InvalidAttributeValue $e) {
            self::assertSame(InvalidAttributeValueReason::OutOfRange, $e->reason());
            self::assertSame(self::STRINGS, $e->attributeId()?->toString());
        }
    }

    public function testHasReportsMembership(): void
    {
        self::assertTrue($this->schema()->has(AttributeId::fromString(self::BRAND)));
        self::assertFalse($this->schema()->has(AttributeId::fromString(self::ELSEWHERE)));
        self::assertFalse(AttributeSchema::none()->has(AttributeId::fromString(self::BRAND)));
    }

    public function testTwoRulesForOneAttributeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AttributeSchema::of(
            AttributeRule::boolean(AttributeId::fromString(self::BRAND), false),
            AttributeRule::text(AttributeId::fromString(self::BRAND), false),
        );
    }

    /** AC-5. */
    public function testMissingFromReturnsExactlyTheRequiredRulesWithNoValueInIdOrder(): void
    {
        // Declared out of id order on purpose: the answer is sorted, not in declaration order.
        $schema = AttributeSchema::of(
            AttributeRule::text(AttributeId::fromString(self::SERIAL), true),
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER)], true),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), 4, 12, false),
        );

        $missing = $schema->missingFrom(AttributeValues::none());

        self::assertSame([self::BRAND, self::SERIAL], array_map(static fn (AttributeId $id): string => $id->toString(), $missing));
    }

    public function testMissingFromIgnoresRequiredRulesThatHaveAValueAndOptionalOnesThatDoNot(): void
    {
        $schema = AttributeSchema::of(
            AttributeRule::choice(AttributeId::fromString(self::BRAND), [AttributeOptionId::fromString(self::FENDER)], true),
            AttributeRule::text(AttributeId::fromString(self::SERIAL), true),
            AttributeRule::number(AttributeId::fromString(self::STRINGS), 4, 12, false),
        );
        $values = AttributeValues::of(AttributeValue::ofOption(AttributeId::fromString(self::BRAND), AttributeOptionId::fromString(self::FENDER)));

        $missing = $schema->missingFrom($values);

        self::assertSame([self::SERIAL], array_map(static fn (AttributeId $id): string => $id->toString(), $missing));
    }

    public function testMissingFromIsEmptyWhenNothingIsRequired(): void
    {
        self::assertSame([], $this->schema()->missingFrom(AttributeValues::none()));
    }
}
