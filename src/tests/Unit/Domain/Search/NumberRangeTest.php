<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Search\ValueObject\NumberRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * search-range-facets AC-3: `NumberRange::of()` — null when empty, ends kept as given, an inverted
 * pair swapped, the whole int64 range representable.
 */
final class NumberRangeTest extends TestCase
{
    public function testNoEndAtAllIsNoRange(): void
    {
        self::assertNull(NumberRange::of(null, null));
    }

    /**
     * @return iterable<string, array{?int, ?int, ?int, ?int}> min, max asked; min, max expected
     */
    public static function ranges(): iterable
    {
        yield 'only a lower end' => [5, null, 5, null];
        yield 'only an upper end' => [null, 5, null, 5];
        yield 'one value' => [5, 5, 5, 5];
        yield 'in order' => [10, 20, 10, 20];
        yield 'inverted is swapped' => [20, 10, 10, 20];
        yield 'negative, inverted' => [-3, -7, -7, -3];
        yield 'the whole int64 range' => [\PHP_INT_MIN, \PHP_INT_MAX, \PHP_INT_MIN, \PHP_INT_MAX];
        yield 'the whole int64 range, inverted' => [\PHP_INT_MAX, \PHP_INT_MIN, \PHP_INT_MIN, \PHP_INT_MAX];
    }

    #[DataProvider('ranges')]
    public function testTheEndsAreKeptAndNeverInverted(?int $min, ?int $max, ?int $expectedMin, ?int $expectedMax): void
    {
        $range = NumberRange::of($min, $max);

        if (null === $range) {
            self::fail('A range with an end was dropped.');
        }
        self::assertSame([$expectedMin, $expectedMax], [$range->min(), $range->max()]);
    }
}
