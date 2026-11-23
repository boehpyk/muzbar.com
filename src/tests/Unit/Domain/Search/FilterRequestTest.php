<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\Currency;
use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\OptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-browse AC-1: `FilterRequest::parse(, [], ['currency' => null, 'min' => null, 'max' => null])` decides format only, drops what is malformed, collapses
 * duplicates, applies the caps and never throws.
 */
final class FilterRequestTest extends TestCase
{
    private const string ATTRIBUTE = '018f5b2a-0000-7000-8000-00000000000a';
    private const string OPTION = '018f5b2a-0000-7000-8000-0000000000b1';

    private static function uuid(int $n): string
    {
        return \sprintf('018f5b2a-0000-7000-8000-%012d', $n);
    }

    /**
     * @return list<string> each surviving attribute id, in order
     */
    private function attributes(FilterRequest $request): array
    {
        return array_map(static fn (array $entry): string => $entry['attribute']->toString(), $request->requested());
    }

    /**
     * @return list<string> the surviving values of the attribute at `$index`, as `yes`/`no`/option id
     */
    private function values(FilterRequest $request, int $index = 0): array
    {
        return array_map(
            static fn (OptionId|bool $v): string => \is_bool($v) ? ($v ? 'yes' : 'no') : $v->toString(),
            $request->requested()[$index]['values'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAttributeKeys(): iterable
    {
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'empty' => [''];
        yield 'uuid with a trailing newline' => [self::ATTRIBUTE."\n"];
        yield 'uuid with a leading space' => [' '.self::ATTRIBUTE];
        yield 'uuid with a NUL byte' => [self::ATTRIBUTE."\0"];
        yield 'one character short' => [substr(self::ATTRIBUTE, 0, -1)];
        yield 'an integer-like key' => ['7'];
    }

    #[DataProvider('malformedAttributeKeys')]
    public function testAnAttributeKeyThatIsNotAWellFormedUuidIsDropped(string $key): void
    {
        $request = FilterRequest::parse([$key => ['yes'], self::ATTRIBUTE => ['no']], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([self::ATTRIBUTE], $this->attributes($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedValues(): iterable
    {
        yield 'upper-case YES' => ['YES'];
        yield 'capitalised No' => ['No'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
        yield 'one' => ['1'];
        yield 'zero' => ['0'];
        yield 'leading space before yes' => [' yes'];
        yield 'trailing newline after yes' => ["yes\n"];
        yield 'uuid with a trailing newline' => [self::OPTION."\n"];
        yield 'uuid with a leading space' => [' '.self::OPTION];
        yield 'uuid with a NUL byte' => [self::OPTION."\0"];
        yield 'empty' => [''];
        yield 'invalid utf-8' => ["\xff\xfe"];
        yield 'not a uuid' => ['not-a-uuid'];
    }

    #[DataProvider('malformedValues')]
    public function testAValueThatIsNeitherAUuidNorExactlyYesOrNoIsDropped(string $value): void
    {
        $request = FilterRequest::parse([self::ATTRIBUTE => [$value, 'yes']], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(['yes'], $this->values($request), 'only the well-formed sibling survives');
    }

    public function testWellFormedValuesSurviveInRequestOrderAndAnUpperCaseUuidIsLowerCased(): void
    {
        $request = FilterRequest::parse([self::ATTRIBUTE => ['no', strtoupper(self::OPTION), 'yes']], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(['no', self::OPTION, 'yes'], $this->values($request));
    }

    public function testDuplicateValuesCollapseIncludingAnIdDifferingOnlyInCase(): void
    {
        $request = FilterRequest::parse([self::ATTRIBUTE => [self::OPTION, 'yes', strtoupper(self::OPTION), 'yes', self::OPTION]], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([self::OPTION, 'yes'], $this->values($request));
    }

    public function testAnAttributeLeftWithNoValueIsDropped(): void
    {
        $request = FilterRequest::parse([
            self::uuid(1) => ['nonsense', 'YES'],
            self::uuid(2) => [],
            self::uuid(3) => ['yes'],
        ], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([self::uuid(3)], $this->attributes($request));
    }

    public function testAnEmptyRequestHasNoAttributesAndNoOptionIds(): void
    {
        $request = FilterRequest::parse([], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([], $request->requested());
        self::assertSame([], $request->requestedOptionIds());
    }

    public function testOnlyTheFirstTwentyAttributesSurviveInRequestOrder(): void
    {
        $raw = [];
        for ($i = 1; $i <= 25; ++$i) {
            $raw[self::uuid($i)] = ['yes'];
        }

        $request = FilterRequest::parse($raw, [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(FilterRequest::MAX_ATTRIBUTES, \count($request->requested()));
        self::assertSame(array_map(self::uuid(...), range(1, 20)), $this->attributes($request));
    }

    public function testMalformedAttributesDoNotConsumeTheAttributeCap(): void
    {
        $raw = [];
        for ($i = 1; $i <= 30; ++$i) {
            $raw['junk-'.$i] = ['yes'];
        }
        for ($i = 1; $i <= 25; ++$i) {
            $raw[self::uuid($i)] = ['yes'];
        }

        $request = FilterRequest::parse($raw, [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(array_map(self::uuid(...), range(1, 20)), $this->attributes($request));
    }

    public function testAnAttributeWithNoSurvivingValueDoesNotConsumeTheAttributeCap(): void
    {
        $raw = [];
        for ($i = 1; $i <= 30; ++$i) {
            $raw[self::uuid($i)] = ['garbage'];
        }
        $raw[self::uuid(100)] = ['yes'];

        $request = FilterRequest::parse($raw, [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([self::uuid(100)], $this->attributes($request));
    }

    public function testOnlyTheFirstFiftyValuesOfAnAttributeSurvive(): void
    {
        $values = [];
        for ($i = 1; $i <= 60; ++$i) {
            $values[] = self::uuid(1000 + $i);
        }

        $request = FilterRequest::parse([self::ATTRIBUTE => $values], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(FilterRequest::MAX_VALUES, \count($request->requested()[0]['values']));
        self::assertSame(\array_slice($values, 0, 50), $this->values($request));
    }

    public function testMalformedAndDuplicateValuesDoNotConsumeTheValueCap(): void
    {
        $values = [];
        for ($i = 1; $i <= 40; ++$i) {
            $values[] = 'junk-'.$i;
            $values[] = self::uuid(1000 + 1);
        }
        for ($i = 2; $i <= 60; ++$i) {
            $values[] = self::uuid(1000 + $i);
        }

        $request = FilterRequest::parse([self::ATTRIBUTE => $values], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(array_map(static fn (int $i): string => self::uuid(1000 + $i), range(1, 50)), $this->values($request));
    }

    public function testRequestedOptionIdsListsEveryOptionOnceAndNoBooleans(): void
    {
        $request = FilterRequest::parse([
            self::uuid(1) => [self::uuid(11), 'yes', self::uuid(12)],
            self::uuid(2) => [self::uuid(12), 'no', self::uuid(13)],
        ], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame(
            [self::uuid(11), self::uuid(12), self::uuid(13)],
            array_map(static fn (OptionId $id): string => $id->toString(), $request->requestedOptionIds()),
        );
    }

    public function testParsingNeverThrowsOnHostileInput(): void
    {
        $request = FilterRequest::parse([
            "\0" => ["\0", "\xff", str_repeat('A', 100000)],
            '' => [''],
            self::ATTRIBUTE => ["\0", "\xff\xfe", "yes\0", self::OPTION],
            '99999999999999999999' => ['yes'],
            -5 => ['yes'],
        ], [], ['currency' => null, 'min' => null, 'max' => null]);

        self::assertSame([self::ATTRIBUTE], $this->attributes($request));
        self::assertSame([self::OPTION], $this->values($request));
    }

    // ---------------------------------------------------------------- search-range-facets AC-1: ranges

    private const array NO_PRICE = ['currency' => null, 'min' => null, 'max' => null];

    /**
     * @param array<array-key, array{min: ?string, max: ?string}> $ranges
     *
     * @return list<array{string, ?int, ?int}> attribute id, min, max — each surviving range, in order
     */
    private function ranges(array $ranges): array
    {
        return array_map(
            static fn (array $e): array => [$e['attribute']->toString(), $e['range']->min(), $e['range']->max()],
            FilterRequest::parse([], $ranges, self::NO_PRICE)->requestedRanges(),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function wellFormedBounds(): iterable
    {
        yield 'ten' => ['10', 10];
        yield 'minus three' => ['-3', -3];
        yield 'leading zeros' => ['007', 7];
        yield 'zero' => ['0', 0];
        yield 'eighteen digits' => ['999999999999999999', 999999999999999999];
        yield 'eighteen digits, negative' => ['-999999999999999999', -999999999999999999];
    }

    #[DataProvider('wellFormedBounds')]
    public function testAWellFormedBoundSurvivesAsAnInteger(string $bound, int $expected): void
    {
        self::assertSame([[self::ATTRIBUTE, $expected, null]], $this->ranges([self::ATTRIBUTE => ['min' => $bound, 'max' => null]]));
        self::assertSame([[self::ATTRIBUTE, null, $expected]], $this->ranges([self::ATTRIBUTE => ['min' => null, 'max' => $bound]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBounds(): iterable
    {
        yield 'a decimal' => ['10.5'];
        yield 'a plus sign' => ['+5'];
        yield 'a leading space' => [' 5'];
        yield 'a trailing space' => ['5 '];
        yield 'an exponent' => ['1e3'];
        yield 'blank' => [''];
        yield 'a trailing newline' => ["5\n"];
        yield 'nineteen digits' => ['9999999999999999999'];
        yield 'a lone minus' => ['-'];
        yield 'a decimal comma' => ['10,5'];
        yield 'arabic-indic digits' => ["\u{0665}"];
        yield 'a NUL byte' => ["5\0"];
    }

    #[DataProvider('malformedBounds')]
    public function testAMalformedBoundIsDroppedAndItsSiblingKept(string $bound): void
    {
        self::assertSame([[self::ATTRIBUTE, null, 7]], $this->ranges([self::ATTRIBUTE => ['min' => $bound, 'max' => '7']]));
    }

    #[DataProvider('malformedBounds')]
    public function testARangeWithNoSurvivingBoundIsDropped(string $bound): void
    {
        self::assertSame([], $this->ranges([self::ATTRIBUTE => ['min' => $bound, 'max' => null]]));
    }

    public function testAnInvertedRangeIsSwapped(): void
    {
        self::assertSame([[self::ATTRIBUTE, 10, 20]], $this->ranges([self::ATTRIBUTE => ['min' => '20', 'max' => '10']]));
    }

    #[DataProvider('malformedAttributeKeys')]
    public function testARangeOnAnAttributeKeyThatIsNotAWellFormedUuidIsDropped(string $key): void
    {
        self::assertSame(
            [[self::ATTRIBUTE, 1, null]],
            $this->ranges([$key => ['min' => '1', 'max' => null], self::ATTRIBUTE => ['min' => '1', 'max' => null]]),
        );
    }

    public function testTwoKeysNamingOneAttributeKeepTheFirstRange(): void
    {
        self::assertSame(
            [[self::ATTRIBUTE, 1, 2]],
            $this->ranges([strtoupper(self::ATTRIBUTE) => ['min' => '1', 'max' => '2'], self::ATTRIBUTE => ['min' => '5', 'max' => null]]),
        );
    }

    public function testRangesDoNotAddOptionIds(): void
    {
        self::assertSame([], FilterRequest::parse([], [self::ATTRIBUTE => ['min' => '1', 'max' => null]], self::NO_PRICE)->requestedOptionIds());
    }

    // ---------------------------------------------------------------- the cap across `f` and `r`

    public function testTheAttributeCapCountsValuesAndRangesTogetherValuesFirst(): void
    {
        $values = [];
        for ($i = 1; $i <= 15; ++$i) {
            $values[self::uuid($i)] = ['yes'];
        }
        $ranges = [];
        for ($i = 101; $i <= 110; ++$i) {
            $ranges[self::uuid($i)] = ['min' => '1', 'max' => null];
        }

        $request = FilterRequest::parse($values, $ranges, self::NO_PRICE);

        self::assertCount(15, $request->requested());
        self::assertSame(
            array_map(self::uuid(...), range(101, 105)),
            array_map(static fn (array $e): string => $e['attribute']->toString(), $request->requestedRanges()),
            'only 5 of the 10 ranges fit under the shared cap of 20',
        );
    }

    public function testARangeOnAnAttributeAlreadyCountedByItsValuesCostsNoSecondSlot(): void
    {
        $values = [];
        for ($i = 1; $i <= 20; ++$i) {
            $values[self::uuid($i)] = ['yes'];
        }

        $request = FilterRequest::parse($values, [
            self::uuid(3) => ['min' => '1', 'max' => null],
            self::uuid(200) => ['min' => '1', 'max' => null],
        ], self::NO_PRICE);

        self::assertSame(
            [self::uuid(3)],
            array_map(static fn (array $e): string => $e['attribute']->toString(), $request->requestedRanges()),
        );
    }

    public function testDroppedRangesDoNotConsumeTheCap(): void
    {
        $ranges = [];
        for ($i = 1; $i <= 30; ++$i) {
            $ranges[self::uuid($i)] = ['min' => 'x', 'max' => ''];
        }
        $ranges[self::uuid(100)] = ['min' => '1', 'max' => null];

        self::assertSame([[self::uuid(100), 1, null]], $this->ranges($ranges));
    }

    // ---------------------------------------------------------------- the price filter

    /**
     * @param array{currency: ?string, min: ?string, max: ?string} $price
     *
     * @return array{string, ?int, ?int}|null currency, min, max in minor units
     */
    private function price(array $price): ?array
    {
        $parsed = FilterRequest::parse([], [], $price)->price();

        return null === $parsed ? null : [$parsed->currency()->value, $parsed->range()?->min(), $parsed->range()?->max()];
    }

    public function testNoPriceIsNoPriceFilter(): void
    {
        self::assertNull($this->price(self::NO_PRICE));
    }

    public function testEveryCodeOfTheMirrorIsAccepted(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertSame([$currency->value, null, null], $this->price(['currency' => $currency->value, 'min' => null, 'max' => null]));
        }
    }

    public function testWholeUnitsBecomeMinorUnitsAndAnInvertedPairIsSwapped(): void
    {
        self::assertSame(['EUR', 10000, null], $this->price(['currency' => 'EUR', 'min' => '100', 'max' => null]));
        self::assertSame(['EUR', 10000, 50000], $this->price(['currency' => 'EUR', 'min' => '500', 'max' => '100']));
        self::assertSame(['SEK', 0, 999999900], $this->price(['currency' => 'SEK', 'min' => '0', 'max' => '9999999']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedCurrencies(): iterable
    {
        yield 'lower case' => ['eur'];
        yield 'a trailing space' => ['EUR '];
        yield 'not on the list' => ['GBP'];
        yield 'removed from the list' => ['UAH'];
        yield 'blank' => [''];
    }

    #[DataProvider('unsupportedCurrencies')]
    public function testWithoutAnAllowedCurrencyThePriceFilterIsDroppedWholeBoundsOrNot(string $currency): void
    {
        self::assertNull($this->price(['currency' => $currency, 'min' => '100', 'max' => '500']));
    }

    public function testBoundsWithoutAnyCurrencyAreDropped(): void
    {
        self::assertNull($this->price(['currency' => null, 'min' => '100', 'max' => '500']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPriceBounds(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'a decimal' => ['10.50'];
        yield 'eight digits' => ['10000000'];
        yield 'a plus sign' => ['+5'];
        yield 'a leading space' => [' 5'];
        yield 'a trailing newline' => ["5\n"];
        yield 'an exponent' => ['1e3'];
        yield 'blank' => [''];
    }

    #[DataProvider('malformedPriceBounds')]
    public function testAMalformedPriceBoundIsDroppedAndTheCurrencyKept(string $bound): void
    {
        self::assertSame(['USD', null, 700], $this->price(['currency' => 'USD', 'min' => $bound, 'max' => '7']));
        self::assertSame(['USD', null, null], $this->price(['currency' => 'USD', 'min' => $bound, 'max' => $bound]));
    }

    public function testParsingRangesAndPriceNeverThrowsOnHostileInput(): void
    {
        $request = FilterRequest::parse([], [
            "\0" => ['min' => "\0", 'max' => "\xff"],
            '' => ['min' => '1', 'max' => '1'],
            -5 => ['min' => '1', 'max' => '1'],
            self::ATTRIBUTE => ['min' => str_repeat('9', 100000), 'max' => '3'],
        ], ['currency' => "\xff\xfe", 'min' => "\0", 'max' => str_repeat('9', 100000)]);

        self::assertSame([self::ATTRIBUTE], array_map(static fn (array $e): string => $e['attribute']->toString(), $request->requestedRanges()));
        self::assertNull($request->price());
    }
}
