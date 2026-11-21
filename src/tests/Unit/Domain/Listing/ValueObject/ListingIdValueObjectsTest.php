<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidCategoryId;
use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Listing\Exception\InvalidSellerId;
use App\Domain\Listing\ValueObject\CategoryId;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\SellerId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The three UUID value objects of the Listing context share one shape and one test. */
final class ListingIdValueObjectsTest extends TestCase
{
    private const string UUID = '018f5b2a-0000-7000-8000-000000000000';

    /**
     * @return iterable<string, array{class-string<ListingId|SellerId|CategoryId>}>
     */
    public static function idClasses(): iterable
    {
        yield 'ListingId' => [ListingId::class];
        yield 'SellerId' => [SellerId::class];
        yield 'CategoryId' => [CategoryId::class];
    }

    /**
     * @return iterable<string, array{class-string<ListingId|SellerId|CategoryId>, class-string<\DomainException>}>
     */
    public static function idClassesWithException(): iterable
    {
        yield 'ListingId' => [ListingId::class, InvalidListingId::class];
        yield 'SellerId' => [SellerId::class, InvalidSellerId::class];
        yield 'CategoryId' => [CategoryId::class, InvalidCategoryId::class];
    }

    /**
     * @param class-string<ListingId|SellerId|CategoryId> $class
     */
    #[DataProvider('idClasses')]
    public function testAcceptsAnRfc4122StringAndNormalisesCase(string $class): void
    {
        self::assertSame(self::UUID, $class::fromString(self::UUID)->toString());
        self::assertSame('abcdef01-0000-7000-8000-00000000abcd', $class::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->toString());
    }

    /**
     * @param class-string<ListingId|SellerId|CategoryId> $class
     * @param class-string<\DomainException>              $exceptionClass
     */
    #[DataProvider('idClassesWithException')]
    public function testRejectsMalformedValues(string $class, string $exceptionClass): void
    {
        foreach (['', 'not-a-uuid', '018f5b2a000070008000000000000000', self::UUID.'x', self::UUID."\n"] as $bad) {
            try {
                $class::fromString($bad);
                self::fail(\sprintf('%s accepted %s', $class, var_export($bad, true)));
            } catch (\DomainException $e) {
                self::assertSame($exceptionClass, $e::class);
            }
        }
    }

    /**
     * @param class-string<ListingId|SellerId|CategoryId> $class
     */
    #[DataProvider('idClasses')]
    public function testEqualsIgnoresHexCaseAndDistinguishesIds(string $class): void
    {
        self::assertTrue($class::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->equals($class::fromString('abcdef01-0000-7000-8000-00000000abcd')));
        self::assertFalse($class::fromString(self::UUID)->equals($class::fromString('018f5b2a-0000-7000-8000-000000000001')));
    }
}
