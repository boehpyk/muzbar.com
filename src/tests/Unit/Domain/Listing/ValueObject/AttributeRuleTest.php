<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeValue;
use App\Domain\Listing\Exception\InvalidAttributeValueReason;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeKind;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AC-1: every input the spec lists, per kind. Every refusal names the attribute. */
final class AttributeRuleTest extends TestCase
{
    private const string ATTR = '018f5b2a-0000-7000-8000-00000000a001';
    private const string OPT_A = '018f5b2a-0000-7000-8000-00000000b001';
    private const string OPT_B = '018f5b2a-0000-7000-8000-00000000b002';
    /** A well-formed option id of *another* attribute. */
    private const string OPT_C = '018f5b2a-0000-7000-8000-00000000b003';

    private function id(): AttributeId
    {
        return AttributeId::fromString(self::ATTR);
    }

    private function choice(): AttributeRule
    {
        return AttributeRule::choice($this->id(), [AttributeOptionId::fromString(self::OPT_A), AttributeOptionId::fromString(self::OPT_B)], false);
    }

    private function assertRefused(AttributeRule $rule, string $raw, InvalidAttributeValueReason $reason): InvalidAttributeValue
    {
        try {
            $rule->read($raw);
        } catch (InvalidAttributeValue $e) {
            self::assertSame($reason, $e->reason(), \sprintf('Wrong reason for %s.', var_export($raw, true)));
            self::assertSame(self::ATTR, $e->attributeId()?->toString(), 'The refusal must name the attribute.');

            return $e;
        }

        self::fail(\sprintf('%s was accepted.', var_export($raw, true)));
    }

    // --- choice

    public function testChoiceAcceptsAnOptionOfThatAttribute(): void
    {
        $value = $this->choice()->read(self::OPT_A);

        self::assertNotNull($value);
        self::assertSame(AttributeKind::Choice, $value->kind());
        self::assertSame(self::OPT_A, $value->optionId()?->toString());
        self::assertSame(self::ATTR, $value->attributeId()->toString());
    }

    public function testChoiceTrimsTheInput(): void
    {
        self::assertSame(self::OPT_A, $this->choice()->read(' '.self::OPT_A." \n")?->optionId()?->toString());
    }

    public function testChoiceAcceptsAnUppercaseSpellingOfAnOption(): void
    {
        self::assertSame(self::OPT_B, $this->choice()->read(strtoupper(self::OPT_B))?->optionId()?->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
        yield 'tab and newline' => ["\t\n"];
    }

    #[DataProvider('emptyInputs')]
    public function testEveryKindReadsEmptyInputAsNoValue(string $raw): void
    {
        $id = $this->id();

        foreach ([$this->choice(), AttributeRule::number($id, 4, 12, false), AttributeRule::boolean($id, false), AttributeRule::text($id, false)] as $rule) {
            self::assertNull($rule->read($raw), $rule->kind()->value);
        }
    }

    public function testChoiceRefusesAWellFormedIdThatIsNotItsOption(): void
    {
        $this->assertRefused($this->choice(), self::OPT_C, InvalidAttributeValueReason::NotAnOption);
    }

    public function testChoiceRefusesSomethingThatIsNotEvenAnId(): void
    {
        $this->assertRefused($this->choice(), 'not-a-uuid', InvalidAttributeValueReason::NotAnOption);
    }

    public function testChoiceWithNoOptionsRefusesEverything(): void
    {
        $this->assertRefused(AttributeRule::choice($this->id(), [], false), self::OPT_A, InvalidAttributeValueReason::NotAnOption);
    }

    // --- number

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function acceptedBoundedNumbers(): iterable
    {
        yield 'lower bound' => ['4', 4];
        yield 'upper bound' => ['12', 12];
        yield 'padded with spaces' => [' 7 ', 7];
        yield 'leading zeros' => ['007', 7];
    }

    #[DataProvider('acceptedBoundedNumbers')]
    public function testNumberAcceptsAWholeNumberWithinInclusiveBounds(string $raw, int $expected): void
    {
        $value = AttributeRule::number($this->id(), 4, 12, false)->read($raw);

        self::assertNotNull($value);
        self::assertSame(AttributeKind::Number, $value->kind());
        self::assertSame($expected, $value->number());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRangeForFourToTwelve(): iterable
    {
        yield 'below' => ['3'];
        yield 'above' => ['13'];
        yield 'negative' => ['-1'];
    }

    #[DataProvider('outOfRangeForFourToTwelve')]
    public function testNumberOutsideTheBoundsIsOutOfRangeAndCarriesTheBounds(string $raw): void
    {
        $e = $this->assertRefused(AttributeRule::number($this->id(), 4, 12, false), $raw, InvalidAttributeValueReason::OutOfRange);

        self::assertSame(4, $e->min());
        self::assertSame(12, $e->max());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notWholeNumbers(): iterable
    {
        yield 'decimal point' => ['7.0'];
        yield 'decimal comma' => ['7,5'];
        yield 'exponent' => ['1e1'];
        yield 'plus sign' => ['+7'];
        yield 'inner space' => ['1 0'];
        yield 'arabic-indic digit' => ['٧'];
        yield 'nineteen digits' => ['1234567890123456789'];
        yield 'words' => ['six'];
        yield 'lone minus' => ['-'];
    }

    #[DataProvider('notWholeNumbers')]
    public function testNumberThatIsNotAWholeNumberIsRefused(string $raw): void
    {
        $this->assertRefused(AttributeRule::number($this->id(), 4, 12, false), $raw, InvalidAttributeValueReason::NotAWholeNumber);
    }

    public function testUnboundedNumberAcceptsNegativeAndEighteenDigitValues(): void
    {
        $rule = AttributeRule::number($this->id(), null, null, false);

        self::assertSame(-5, $rule->read('-5')?->number());
        self::assertSame(999999999999999999, $rule->read('999999999999999999')?->number());
    }

    public function testNumberWithOnlyAMinimumAcceptsALargeValueAndRefusesBelowIt(): void
    {
        $rule = AttributeRule::number($this->id(), 4, null, false);

        self::assertSame(1000000, $rule->read('1000000')?->number());

        $e = $this->assertRefused($rule, '3', InvalidAttributeValueReason::OutOfRange);
        self::assertSame(4, $e->min());
        self::assertNull($e->max());
    }

    public function testNumberWithOnlyAMaximumRefusesAboveItAndAcceptsBelowIt(): void
    {
        $rule = AttributeRule::number($this->id(), null, 12, false);

        self::assertSame(-100, $rule->read('-100')?->number());

        $e = $this->assertRefused($rule, '13', InvalidAttributeValueReason::OutOfRange);
        self::assertNull($e->min());
        self::assertSame(12, $e->max());
    }

    public function testNumberBoundsAreInclusiveOnBothSides(): void
    {
        $rule = AttributeRule::number($this->id(), 7, 7, false);

        self::assertSame(7, $rule->read('7')?->number());
        $this->assertRefused($rule, '6', InvalidAttributeValueReason::OutOfRange);
        $this->assertRefused($rule, '8', InvalidAttributeValueReason::OutOfRange);
    }

    // --- boolean

    public function testBooleanReadsYesAndNo(): void
    {
        $rule = AttributeRule::boolean($this->id(), false);

        $no = $rule->read('no');

        self::assertTrue($rule->read('yes')?->boolean());
        self::assertNotNull($no);
        self::assertFalse($no->boolean());
        self::assertSame(AttributeKind::Boolean, $no->kind());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notYesOrNo(): iterable
    {
        yield 'capitalised' => ['Yes'];
        yield 'one' => ['1'];
        yield 'true' => ['true'];
        yield 'on' => ['on'];
        yield 'zero' => ['0'];
    }

    #[DataProvider('notYesOrNo')]
    public function testBooleanRefusesAnythingButLowercaseYesOrNo(string $raw): void
    {
        $this->assertRefused(AttributeRule::boolean($this->id(), false), $raw, InvalidAttributeValueReason::NotYesOrNo);
    }

    // --- text

    public function testTextIsTrimmed(): void
    {
        $value = AttributeRule::text($this->id(), false)->read(' SN-123 ');

        self::assertNotNull($value);
        self::assertSame('SN-123', $value->text());
        self::assertSame(AttributeKind::Text, $value->kind());
    }

    public function testTextCountsCharactersNotBytes(): void
    {
        $text = str_repeat('ї', 200);

        self::assertSame($text, AttributeRule::text($this->id(), false)->read($text)?->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTexts(): iterable
    {
        yield '201 characters' => [str_repeat('a', 201)];
        yield '201 multibyte characters' => [str_repeat('ї', 201)];
        yield 'newline' => ["a\nb"];
        yield 'tab' => ["a\tb"];
        yield 'NUL' => ["a\0b"];
        yield 'carriage return' => ["a\rb"];
        yield 'invalid UTF-8' => ["\xC3\x28"];
    }

    #[DataProvider('badTexts')]
    public function testTextThatIsTooLongMultiLineOrControlLadenIsRefused(string $raw): void
    {
        $this->assertRefused(AttributeRule::text($this->id(), false), $raw, InvalidAttributeValueReason::BadText);
    }
}
