<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\ValueObject\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AC-2. */
final class CurrencyTest extends TestCase
{
    public function testTheCasesAreExactlyTheAllowedCodes(): void
    {
        self::assertSame(
            ['USD', 'EUR', 'SEK', 'UAH'],
            array_map(static fn (Currency $c): string => $c->name, Currency::cases()),
        );
        foreach (Currency::cases() as $currency) {
            self::assertSame($currency->name, $currency->value);
        }
    }

    public function testEveryCurrencyHasTwoMinorUnits(): void
    {
        foreach (Currency::cases() as $currency) {
            self::assertSame(2, $currency->minorUnits());
        }
    }

    public function testFromCodeResolvesAnExactCode(): void
    {
        self::assertSame(Currency::UAH, Currency::fromCode('UAH'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupported(): iterable
    {
        yield 'not on the list' => ['GBP'];
        yield 'lower case' => ['eur'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsupported')]
    public function testFromCodeRejectsAnythingElse(string $code): void
    {
        $this->expectException(InvalidPrice::class);

        Currency::fromCode($code);
    }
}
