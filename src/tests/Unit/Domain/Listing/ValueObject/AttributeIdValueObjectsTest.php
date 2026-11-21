<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Listing\ValueObject;

use App\Domain\Listing\Exception\InvalidAttributeId;
use App\Domain\Listing\Exception\InvalidAttributeOptionId;
use App\Domain\Listing\ValueObject\AttributeId;
use App\Domain\Listing\ValueObject\AttributeOptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Listing's local copies of Catalog's two ids share one shape and one test. */
final class AttributeIdValueObjectsTest extends TestCase
{
    private const string UUID = '018f5b2a-0000-7000-8000-000000000000';

    /**
     * @return iterable<string, array{class-string<AttributeId|AttributeOptionId>}>
     */
    public static function idClasses(): iterable
    {
        yield 'AttributeId' => [AttributeId::class];
        yield 'AttributeOptionId' => [AttributeOptionId::class];
    }

    /**
     * @return iterable<string, array{class-string<AttributeId|AttributeOptionId>, class-string<\DomainException>}>
     */
    public static function idClassesWithException(): iterable
    {
        yield 'AttributeId' => [AttributeId::class, InvalidAttributeId::class];
        yield 'AttributeOptionId' => [AttributeOptionId::class, InvalidAttributeOptionId::class];
    }

    /**
     * @param class-string<AttributeId|AttributeOptionId> $class
     */
    #[DataProvider('idClasses')]
    public function testAcceptsAnRfc4122StringAndNormalisesCase(string $class): void
    {
        self::assertSame(self::UUID, $class::fromString(self::UUID)->toString());
        self::assertSame('abcdef01-0000-7000-8000-00000000abcd', $class::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->toString());
    }

    /**
     * @param class-string<AttributeId|AttributeOptionId> $class
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
     * @param class-string<AttributeId|AttributeOptionId> $class
     */
    #[DataProvider('idClasses')]
    public function testEqualsIgnoresHexCaseAndDistinguishesIds(string $class): void
    {
        self::assertTrue($class::fromString('ABCDEF01-0000-7000-8000-00000000ABCD')->equals($class::fromString('abcdef01-0000-7000-8000-00000000abcd')));
        self::assertFalse($class::fromString(self::UUID)->equals($class::fromString('018f5b2a-0000-7000-8000-000000000001')));
    }
}
