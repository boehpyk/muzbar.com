<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Catalog\ValueObject;

use App\Domain\Catalog\Exception\InvalidNumberRules;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?int, ?int}>
     */
    public static function accepted(): iterable
    {
        yield 'all null' => [null, null, null];
        yield 'unit only' => ['mm', null, null];
        yield 'minimum only' => [null, 4, null];
        yield 'maximum only' => [null, null, 12];
        yield 'both' => ['mm', 4, 12];
        yield 'equal bounds' => [null, 6, 6];
        yield 'negative range' => [null, -10, -5];
    }

    #[DataProvider('accepted')]
    public function testAcceptsEveryValidCombinationAndKeepsIt(?string $unit, ?int $min, ?int $max): void
    {
        $rules = NumberRules::of(null === $unit ? null : Unit::fromString($unit), $min, $max);

        self::assertSame($unit, $rules->unit()?->toString());
        self::assertSame($min, $rules->minimum());
        self::assertSame($max, $rules->maximum());
    }

    public function testRejectsAMinimumAboveTheMaximum(): void
    {
        $this->expectException(InvalidNumberRules::class);

        NumberRules::of(null, 7, 6);
    }
}
