<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\Entity;

use App\Domain\Catalog\Entity\Attribute;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\AttributeRuleNotApplicable;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeOptions;
use App\Domain\Catalog\ValueObject\AttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCodes;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Domain\Catalog\ValueObject\CategoryId;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AttributeTest extends TestCase
{
    private static function id(): AttributeId
    {
        return AttributeId::fromString(Uuid::v7()->toRfc4122());
    }

    private static function categoryId(): CategoryId
    {
        return CategoryId::fromString(Uuid::v7()->toRfc4122());
    }

    private static function label(string $value = 'Orientation'): AttributeLabel
    {
        return AttributeLabel::fromString($value);
    }

    private static function code(string $value = 'orientation'): AttributeCode
    {
        return AttributeCode::fromString($value);
    }

    /**
     * @param list<string> $options
     */
    private static function choice(array $options = ['Left', 'Right']): Attribute
    {
        return Attribute::defineChoice(
            self::id(),
            self::categoryId(),
            self::code(),
            self::label(),
            false,
            array_map(self::label(...), $options),
            AttributeCodes::none(),
        );
    }

    private static function number(): Attribute
    {
        return Attribute::defineNumber(
            self::id(),
            self::categoryId(),
            self::code('scale'),
            self::label('Scale'),
            true,
            NumberRules::of(Unit::fromString('mm'), 600, 700),
            AttributeCodes::none(),
        );
    }

    private static function boolean(): Attribute
    {
        return Attribute::defineBoolean(self::id(), self::categoryId(), self::code('case'), self::label('Has case'), false, AttributeCodes::none());
    }

    private static function text(): Attribute
    {
        return Attribute::defineText(self::id(), self::categoryId(), self::code('serial'), self::label('Serial'), false, AttributeCodes::none());
    }

    public function testDefineNumberCarriesItsRules(): void
    {
        $id = self::id();
        $categoryId = self::categoryId();
        $rules = NumberRules::of(null, 1, 2);

        $attribute = Attribute::defineNumber($id, $categoryId, self::code('x'), self::label('X'), true, $rules, AttributeCodes::none());

        self::assertTrue($attribute->id()->equals($id));
        self::assertTrue($attribute->categoryId()->equals($categoryId));
        self::assertSame('x', $attribute->code()->toString());
        self::assertSame('X', $attribute->label()->toString());
        self::assertSame(AttributeType::Number, $attribute->type());
        self::assertTrue($attribute->isRequired());
        self::assertSame($rules, $attribute->numberRules());
    }

    /**
     * @return iterable<string, array{\Closure(): Attribute, AttributeType}>
     */
    public static function rulelessAttributes(): iterable
    {
        yield 'choice' => [static fn (): Attribute => self::choice(), AttributeType::Choice];
        yield 'boolean' => [static fn (): Attribute => self::boolean(), AttributeType::Boolean];
        yield 'text' => [static fn (): Attribute => self::text(), AttributeType::Text];
    }

    /**
     * @param \Closure(): Attribute $make
     */
    #[DataProvider('rulelessAttributes')]
    public function testNonNumberTypesHaveNoNumberRules(\Closure $make, AttributeType $type): void
    {
        $attribute = $make();

        self::assertSame($type, $attribute->type());
        self::assertNull($attribute->numberRules());
    }

    public function testDefineChoiceKeepsTheGivenIdentityFields(): void
    {
        $id = self::id();
        $categoryId = self::categoryId();

        $attribute = Attribute::defineChoice($id, $categoryId, self::code('o'), self::label('O'), true, [self::label('A')], AttributeCodes::none());

        self::assertTrue($attribute->id()->equals($id));
        self::assertTrue($attribute->categoryId()->equals($categoryId));
        self::assertSame('o', $attribute->code()->toString());
        self::assertTrue($attribute->isRequired());
    }

    public function testDefineChoiceWithNoOptionsIsRefused(): void
    {
        $this->expectException(InvalidAttributeOptions::class);

        self::choice([]);
    }

    public function testDefineChoiceRefusesLabelsThatDifferOnlyByCaseAndWhitespace(): void
    {
        try {
            self::choice(['Left', ' left ']);
            self::fail('Expected DuplicateAttributeOption.');
        } catch (DuplicateAttributeOption $e) {
            self::assertSame('left', $e->label()->toString());
        }
    }

    public function testDefineChoiceAcceptsFiveThousandOptions(): void
    {
        $labels = array_map(static fn (int $i): string => 'Brand '.$i, range(1, 5000));

        self::assertSame(AttributeType::Choice, self::choice($labels)->type());
    }

    public function testNoMaximumOptionCountIsDeclared(): void
    {
        self::assertFalse((new \ReflectionClass(Attribute::class))->hasConstant('MAX_OPTIONS'));
    }

    public function testNewOptionOnAChoiceBelongsToThatAttribute(): void
    {
        $attribute = self::choice();
        $optionId = AttributeOptionId::fromString(Uuid::v7()->toRfc4122());

        $option = $attribute->newOption($optionId, self::label('Centre'));

        self::assertTrue($option->attributeId()->equals($attribute->id()));
        self::assertTrue($option->id()->equals($optionId));
        self::assertSame('Centre', $option->label()->toString());
    }

    /**
     * @return iterable<string, array{\Closure(): Attribute}>
     */
    public static function nonChoiceAttributes(): iterable
    {
        yield 'number' => [static fn (): Attribute => self::number()];
        yield 'boolean' => [static fn (): Attribute => self::boolean()];
        yield 'text' => [static fn (): Attribute => self::text()];
    }

    /**
     * @param \Closure(): Attribute $make
     */
    #[DataProvider('nonChoiceAttributes')]
    public function testNewOptionOnANonChoiceIsRefused(\Closure $make): void
    {
        $this->expectException(AttributeRuleNotApplicable::class);

        $make()->newOption(AttributeOptionId::fromString(Uuid::v7()->toRfc4122()), self::label('X'));
    }

    /**
     * @return iterable<string, array{\Closure(): Attribute}>
     */
    public static function nonNumberAttributes(): iterable
    {
        yield 'choice' => [static fn (): Attribute => self::choice()];
        yield 'boolean' => [static fn (): Attribute => self::boolean()];
        yield 'text' => [static fn (): Attribute => self::text()];
    }

    /**
     * @param \Closure(): Attribute $make
     */
    #[DataProvider('nonNumberAttributes')]
    public function testChangeNumberRulesOnANonNumberIsRefusedAndChangesNothing(\Closure $make): void
    {
        $attribute = $make();

        try {
            $attribute->changeNumberRules(NumberRules::of(null, 1, 2));
            self::fail('Expected AttributeRuleNotApplicable.');
        } catch (AttributeRuleNotApplicable) {
            self::assertNull($attribute->numberRules());
        }
    }

    public function testChangeNumberRulesReplacesTheRulesOfANumber(): void
    {
        $attribute = self::number();
        $new = NumberRules::of(null, 1, 9);

        $attribute->changeNumberRules($new);

        self::assertSame($new, $attribute->numberRules());
    }

    public function testRelabelRecodeAndChangeRequiredUpdateOnlyTheirOwnField(): void
    {
        $attribute = self::choice();
        $id = $attribute->id();
        $categoryId = $attribute->categoryId();

        $attribute->relabel(self::label('Handedness'));
        $attribute->recode(self::code('handedness'), AttributeCodes::none());
        $attribute->changeRequired(true);

        self::assertSame('Handedness', $attribute->label()->toString());
        self::assertSame('handedness', $attribute->code()->toString());
        self::assertTrue($attribute->isRequired());
        self::assertTrue($attribute->id()->equals($id));
        self::assertTrue($attribute->categoryId()->equals($categoryId));
        self::assertSame(AttributeType::Choice, $attribute->type());
    }

    public function testRemovingTheLastOptionIsRefused(): void
    {
        $this->expectException(AttributeNeedsAnOption::class);

        self::choice()->allowOptionRemoval(1);
    }

    public function testRemovingOneOfTwoOptionsIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        self::choice()->allowOptionRemoval(2);
    }

    /**
     * @param \Closure(): Attribute $make
     */
    #[DataProvider('nonChoiceAttributes')]
    public function testAllowOptionRemovalOnANonChoiceIsRefused(\Closure $make): void
    {
        $this->expectException(AttributeRuleNotApplicable::class);

        $make()->allowOptionRemoval(2);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function impossibleCounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('impossibleCounts')]
    public function testACountBelowOneIsACallerBug(int $count): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::choice()->allowOptionRemoval($count);
    }

    public function testPublicSurfaceHasNoWayToChangeIdentityOwnerOrType(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Attribute::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $allowed = [
            'allowOptionRemoval', 'categoryId', 'changeNumberRules', 'changeRequired', 'code', 'defineBoolean',
            'defineChoice', 'defineNumber', 'defineText', 'id', 'isRequired', 'label', 'newOption', 'numberRules',
            'recode', 'relabel', 'type',
        ];

        self::assertSame($allowed, $methods);
    }

    public function testDoesNotRecordDomainEvents(): void
    {
        self::assertSame([], class_uses(Attribute::class));
    }
}
