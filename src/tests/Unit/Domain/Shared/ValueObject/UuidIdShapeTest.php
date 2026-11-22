<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Shared\ValueObject;

use App\Domain\Catalog\ValueObject\AttributeId as CatalogAttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId as CatalogAttributeOptionId;
use App\Domain\Catalog\ValueObject\CategoryId as CatalogCategoryId;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Listing\ValueObject\AttributeId as ListingAttributeId;
use App\Domain\Listing\ValueObject\AttributeOptionId as ListingAttributeOptionId;
use App\Domain\Listing\ValueObject\CategoryId as ListingCategoryId;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\SellerId;
use App\Domain\Search\ValueObject\AttributeId as SearchAttributeId;
use App\Domain\Search\ValueObject\CategoryId as SearchCategoryId;
use App\Domain\Search\ValueObject\OptionId as SearchOptionId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The proof ADR-0009's 2026-11-14 amendment asked for: a cross-type `equals()` stays a `TypeError`
 * although all fourteen ids now get `equals(self $other)` from one trait, because `self` in a trait
 * resolves to the *using* class. The ring (row n against row n+1, the last against the first) puts every
 * id on both sides of a comparison with a different type, and the same-named pairs across contexts
 * (Catalog/Listing `CategoryId`, `AttributeId`, `AttributeOptionId`) sit adjacent, where a shared
 * base class or interface would be most tempting.
 */
final class UuidIdShapeTest extends TestCase
{
    private const string UUID = '018f5b2a-0000-7000-8000-000000000000';

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function ring(): iterable
    {
        yield 'Identity UserId vs EmailVerificationRequestId' => [UserId::class, EmailVerificationRequestId::class];
        yield 'EmailVerificationRequestId vs PasswordResetRequestId' => [EmailVerificationRequestId::class, PasswordResetRequestId::class];
        yield 'PasswordResetRequestId vs Catalog CategoryId' => [PasswordResetRequestId::class, CatalogCategoryId::class];
        yield 'Catalog CategoryId vs Listing CategoryId' => [CatalogCategoryId::class, ListingCategoryId::class];
        yield 'Listing CategoryId vs Catalog AttributeId' => [ListingCategoryId::class, CatalogAttributeId::class];
        yield 'Catalog AttributeId vs Listing AttributeId' => [CatalogAttributeId::class, ListingAttributeId::class];
        yield 'Listing AttributeId vs Catalog AttributeOptionId' => [ListingAttributeId::class, CatalogAttributeOptionId::class];
        yield 'Catalog AttributeOptionId vs Listing AttributeOptionId' => [CatalogAttributeOptionId::class, ListingAttributeOptionId::class];
        yield 'Listing AttributeOptionId vs ListingId' => [ListingAttributeOptionId::class, ListingId::class];
        yield 'ListingId vs SellerId' => [ListingId::class, SellerId::class];
        yield 'SellerId vs Search CategoryId' => [SellerId::class, SearchCategoryId::class];
        yield 'Search CategoryId vs Catalog CategoryId' => [SearchCategoryId::class, CatalogCategoryId::class];
        yield 'Catalog CategoryId vs Search AttributeId' => [CatalogCategoryId::class, SearchAttributeId::class];
        yield 'Search AttributeId vs Catalog AttributeId' => [SearchAttributeId::class, CatalogAttributeId::class];
        yield 'Catalog AttributeId vs Search OptionId' => [CatalogAttributeId::class, SearchOptionId::class];
        yield 'Search OptionId vs Catalog AttributeOptionId' => [SearchOptionId::class, CatalogAttributeOptionId::class];
        yield 'Catalog AttributeOptionId vs Search CategoryId' => [CatalogAttributeOptionId::class, SearchCategoryId::class];
        yield 'Search CategoryId vs Search AttributeId' => [SearchCategoryId::class, SearchAttributeId::class];
        yield 'Search AttributeId vs Search OptionId' => [SearchAttributeId::class, SearchOptionId::class];
        yield 'Search OptionId vs Identity UserId (closes the ring)' => [SearchOptionId::class, UserId::class];
    }

    /**
     * @param class-string $a
     * @param class-string $b
     */
    #[DataProvider('ring')]
    public function testEqualsRefusesAnIdOfAnotherTypeHoldingTheSameUuid(string $a, string $b): void
    {
        $left = $a::fromString(self::UUID);
        $right = $b::fromString(self::UUID);

        $this->expectException(\TypeError::class);

        // @phpstan-ignore method.nonObject (a dynamic class-string; the runtime TypeError is the behaviour under test)
        $left->equals($right);
    }
}
