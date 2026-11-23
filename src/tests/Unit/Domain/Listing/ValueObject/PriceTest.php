<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidPrice;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\Price;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AC-1. */
final class PriceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Currency, int}>
     */
    public static function accepted(): iterable
    {
        yield 'one decimal digit' => ['1234.5', Currency::EUR, 123450];
        yield 'comma separator' => ['1234,50', Currency::EUR, 123450];
        yield 'whole number in SEK' => ['7', Currency::SEK, 700];
        yield 'surrounding whitespace' => [' 12.00 ', Currency::USD, 1200];
        yield 'one short through a float (0.29)' => ['0.29', Currency::EUR, 29];
        yield 'one short through a float (1.15)' => ['1.15', Currency::EUR, 115];
        yield 'the maximum' => ['9999999.99', Currency::SEK, 999_999_999];
    }

    #[DataProvider('accepted')]
    public function testParsesDecimalTextIntoMinorUnits(string $text, Currency $currency, int $expected): void
    {
        $price = Price::fromDecimal($text, $currency);

        self::assertSame($expected, $price->amountInMinorUnits());
        self::assertSame($currency, $price->currency());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'zero with fraction' => ['0.00'];
        yield 'negative' => ['-1'];
        yield 'too many decimals' => ['1.234'];
        yield 'exponent' => ['1e3'];
        yield 'thousands space' => ['1 000'];
        yield 'trailing separator' => ['12.'];
        yield 'leading separator' => ['.5'];
        yield 'arabic-indic digits' => ['١٢'];
        yield 'over the maximum' => ['10000000.00'];
        yield 'trailing newline' => ["12.50\n12"];
    }

    #[DataProvider('rejected')]
    public function testRejectsMalformedOrOutOfRangeText(string $text): void
    {
        $this->expectException(InvalidPrice::class);

        Price::fromDecimal($text, Currency::EUR);
    }

    public function testRejectsAnAmountBelowOneMinorUnit(): void
    {
        $this->expectException(InvalidPrice::class);

        Price::fromMinorUnits(0, Currency::EUR);
    }

    public function testRejectsAnAmountAboveTheMaximum(): void
    {
        $this->expectException(InvalidPrice::class);

        Price::fromMinorUnits(1_000_000_000, Currency::EUR);
    }

    public function testAcceptsOneMinorUnit(): void
    {
        self::assertSame(1, Price::fromMinorUnits(1, Currency::EUR)->amountInMinorUnits());
    }

    public function testRendersEveryMinorDigit(): void
    {
        self::assertSame('1234.50', Price::fromMinorUnits(123450, Currency::EUR)->toDecimalString());
        self::assertSame('7.00', Price::fromMinorUnits(700, Currency::SEK)->toDecimalString());
        self::assertSame('0.05', Price::fromMinorUnits(5, Currency::USD)->toDecimalString());
    }

    public function testEqualsComparesAmountAndCurrency(): void
    {
        $euro = Price::fromMinorUnits(100, Currency::EUR);

        self::assertTrue($euro->equals(Price::fromMinorUnits(100, Currency::EUR)));
        self::assertFalse($euro->equals(Price::fromMinorUnits(100, Currency::USD)));
        self::assertFalse($euro->equals(Price::fromMinorUnits(101, Currency::EUR)));
    }

    public function testNoFloatAppearsInThePriceSource(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 5).'/src/Domain/Listing/ValueObject/Price.php');
        // Strip comments: the docblock explains the float trap in words.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        self::assertGreaterThan(500, \strlen($code), 'The scan read (almost) nothing; the path is wrong.');
        self::assertDoesNotMatchRegularExpression('/float|floatval|doubleval|\(double\)|round\(|number_format|bcmul|\*\s*100\b/i', $code);
    }
}
