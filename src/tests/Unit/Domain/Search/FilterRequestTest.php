<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\FilterRequest;
use App\Domain\Search\ValueObject\OptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-browse AC-1: `FilterRequest::parse()` decides format only, drops what is malformed, collapses
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
        $request = FilterRequest::parse([$key => ['yes'], self::ATTRIBUTE => ['no']]);

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
        $request = FilterRequest::parse([self::ATTRIBUTE => [$value, 'yes']]);

        self::assertSame(['yes'], $this->values($request), 'only the well-formed sibling survives');
    }

    public function testWellFormedValuesSurviveInRequestOrderAndAnUpperCaseUuidIsLowerCased(): void
    {
        $request = FilterRequest::parse([self::ATTRIBUTE => ['no', strtoupper(self::OPTION), 'yes']]);

        self::assertSame(['no', self::OPTION, 'yes'], $this->values($request));
    }

    public function testDuplicateValuesCollapseIncludingAnIdDifferingOnlyInCase(): void
    {
        $request = FilterRequest::parse([self::ATTRIBUTE => [self::OPTION, 'yes', strtoupper(self::OPTION), 'yes', self::OPTION]]);

        self::assertSame([self::OPTION, 'yes'], $this->values($request));
    }

    public function testAnAttributeLeftWithNoValueIsDropped(): void
    {
        $request = FilterRequest::parse([
            self::uuid(1) => ['nonsense', 'YES'],
            self::uuid(2) => [],
            self::uuid(3) => ['yes'],
        ]);

        self::assertSame([self::uuid(3)], $this->attributes($request));
    }

    public function testAnEmptyRequestHasNoAttributesAndNoOptionIds(): void
    {
        $request = FilterRequest::parse([]);

        self::assertSame([], $request->requested());
        self::assertSame([], $request->requestedOptionIds());
    }

    public function testOnlyTheFirstTwentyAttributesSurviveInRequestOrder(): void
    {
        $raw = [];
        for ($i = 1; $i <= 25; ++$i) {
            $raw[self::uuid($i)] = ['yes'];
        }

        $request = FilterRequest::parse($raw);

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

        $request = FilterRequest::parse($raw);

        self::assertSame(array_map(self::uuid(...), range(1, 20)), $this->attributes($request));
    }

    public function testAnAttributeWithNoSurvivingValueDoesNotConsumeTheAttributeCap(): void
    {
        $raw = [];
        for ($i = 1; $i <= 30; ++$i) {
            $raw[self::uuid($i)] = ['garbage'];
        }
        $raw[self::uuid(100)] = ['yes'];

        $request = FilterRequest::parse($raw);

        self::assertSame([self::uuid(100)], $this->attributes($request));
    }

    public function testOnlyTheFirstFiftyValuesOfAnAttributeSurvive(): void
    {
        $values = [];
        for ($i = 1; $i <= 60; ++$i) {
            $values[] = self::uuid(1000 + $i);
        }

        $request = FilterRequest::parse([self::ATTRIBUTE => $values]);

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

        $request = FilterRequest::parse([self::ATTRIBUTE => $values]);

        self::assertSame(array_map(static fn (int $i): string => self::uuid(1000 + $i), range(1, 50)), $this->values($request));
    }

    public function testRequestedOptionIdsListsEveryOptionOnceAndNoBooleans(): void
    {
        $request = FilterRequest::parse([
            self::uuid(1) => [self::uuid(11), 'yes', self::uuid(12)],
            self::uuid(2) => [self::uuid(12), 'no', self::uuid(13)],
        ]);

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
        ]);

        self::assertSame([self::ATTRIBUTE], $this->attributes($request));
        self::assertSame([self::OPTION], $this->values($request));
    }
}
