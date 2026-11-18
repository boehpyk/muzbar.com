<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeKind;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeValue;
use PHPUnit\Framework\TestCase;

final class AttributeValueTest extends TestCase
{
    private const string A = '018f5b2a-0000-7000-8000-00000000a001';
    private const string B = '018f5b2a-0000-7000-8000-00000000a002';
    private const string OPT = '018f5b2a-0000-7000-8000-00000000b001';

    public function testEachKindExposesOnlyItsOwnReader(): void
    {
        $a = AttributeId::fromString(self::A);

        $option = AttributeValue::ofOption($a, AttributeOptionId::fromString(self::OPT));
        self::assertSame(AttributeKind::Choice, $option->kind());
        self::assertSame(self::OPT, $option->optionId()?->toString());
        self::assertNull($option->number());
        self::assertNull($option->boolean());
        self::assertNull($option->text());

        $number = AttributeValue::ofNumber($a, 0);
        self::assertSame(0, $number->number());
        self::assertNull($number->optionId());
        self::assertNull($number->boolean());
        self::assertNull($number->text());

        $boolean = AttributeValue::ofBoolean($a, false);
        self::assertFalse($boolean->boolean());
        self::assertNull($boolean->number());
        self::assertNull($boolean->text());

        $text = AttributeValue::ofText($a, 'x');
        self::assertSame('x', $text->text());
        self::assertNull($text->optionId());
        self::assertNull($text->boolean());
    }

    public function testTheTextRuleHoldsWithoutAnyRuleInFront(): void
    {
        foreach (["a\0b", "a\nb", '', '   ', str_repeat('x', 201), "\xC3\x28"] as $bad) {
            try {
                AttributeValue::ofText(AttributeId::fromString(self::A), $bad);
                self::fail(\sprintf('%s was accepted.', var_export($bad, true)));
            } catch (InvalidAttributeValue $e) {
                self::assertSame(InvalidAttributeValueReason::BadText, $e->reason());
            }
        }
    }

    public function testEqualsComparesAttributeKindAndPayload(): void
    {
        $a = AttributeId::fromString(self::A);
        $b = AttributeId::fromString(self::B);

        self::assertTrue(AttributeValue::ofNumber($a, 6)->equals(AttributeValue::ofNumber($a, 6)));
        self::assertFalse(AttributeValue::ofNumber($a, 6)->equals(AttributeValue::ofNumber($a, 7)));
        self::assertFalse(AttributeValue::ofNumber($a, 6)->equals(AttributeValue::ofNumber($b, 6)));
        self::assertFalse(AttributeValue::ofBoolean($a, true)->equals(AttributeValue::ofBoolean($a, false)));
        self::assertFalse(AttributeValue::ofBoolean($a, false)->equals(AttributeValue::ofNumber($a, 0)));
        self::assertTrue(AttributeValue::ofText($a, 'SN-1')->equals(AttributeValue::ofText($a, ' SN-1 ')));
        self::assertTrue(
            AttributeValue::ofOption($a, AttributeOptionId::fromString(self::OPT))->equals(AttributeValue::ofOption($a, AttributeOptionId::fromString(strtoupper(self::OPT)))),
        );
    }
}
