<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Search;

use App\Domain\Listing\ValueObject\Currency as ListingCurrency;
use App\Domain\Search\ValueObject\Currency as SearchCurrency;
use PHPUnit\Framework\TestCase;

/**
 * search-range-facets AC-5 (S-14): Search's `Currency` is a mirror of Listing's, copied by value
 * because Search's Domain may not import Listing. This test is the mirror's only guard — it reads
 * both enums, so removing a case, reordering them or changing an exponent on **either** side fails it.
 */
final class CurrencyMirrorTest extends TestCase
{
    public function testTheCasesAreTheSameInTheSameOrder(): void
    {
        self::assertSame(
            array_map(static fn (ListingCurrency $c): array => [$c->name, $c->value], ListingCurrency::cases()),
            array_map(static fn (SearchCurrency $c): array => [$c->name, $c->value], SearchCurrency::cases()),
        );
    }

    public function testEveryCaseHasTheSameMinorUnits(): void
    {
        self::assertNotSame([], ListingCurrency::cases());

        foreach (ListingCurrency::cases() as $listing) {
            $search = SearchCurrency::from($listing->value); // a missing case is a ValueError: the test errors
            self::assertSame($listing->minorUnits(), $search->minorUnits(), $listing->value);
        }
    }
}
