<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine;

use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Listing\Exception\InvalidSellerId;
use App\Domain\Listing\ValueObject\Currency;
use App\Domain\Listing\ValueObject\ListingId;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Listing\ValueObject\SellerId;
use Doctrine\DBAL\Connection;

/**
 * A seller's own drafts, for "my drafts" and the edit form's pre-fill (plan D8, D13). A read model
 * with no port, the `DbalCategoryOutline` shape: only controllers consume it, and it goes through
 * DBAL so it never serves a stale object from the identity map.
 *
 * **The seller filter is in the SQL of both methods.** It is the read side of L-6: a draft is
 * private, and someone else's id must look exactly like an unknown one (AC-29). Never filter in PHP.
 *
 * `price` is the decimal string, formatted by `Price::toDecimalString()` — the Domain's one
 * definition of how minor units read back, so no float is ever involved.
 *
 * **`findPublished()` is the public page's read, and the one method here without a seller filter.**
 * It filters `status = 'published'` in SQL instead, and it **never selects `seller_id`**: the column
 * a page cannot leak is the one it never read (listing-publish D10, Constitution §8).
 *
 * `publishedAt` is an ISO 8601 string (`DATE_ATOM`), null for a listing that was never published.
 *
 * @phpstan-type DraftRow array{id: string, categoryId: string, title: string, description: string, price: string, currency: string, status: string, publishedAt: ?string}
 * @phpstan-type PublicRow array{id: string, categoryId: string, title: string, description: string, price: string, currency: string, publishedAt: string}
 */
final readonly class DbalSellerDrafts
{
    // Never `seller_id` (see the class docblock): the seller filters name it, no select list does.
    private const string COLUMNS = 'id, category_id, title, description, price_amount, price_currency, published_at';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Newest first: UUIDv7 ids are minted in time order (plan D11).
     *
     * @return list<DraftRow>
     */
    public function forSeller(string $sellerId): array
    {
        if (null === ($seller = self::sellerId($sellerId))) {
            return [];
        }

        /** @var list<array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string, published_at: ?string, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.', status FROM listing_listing WHERE seller_id = :seller ORDER BY id DESC',
            ['seller' => $seller->toString()],
        );

        return array_map(self::toDraftRow(...), $rows);
    }

    /**
     * The draft, if it exists **and** belongs to `$sellerId`; null otherwise — including for a
     * malformed id, which is answered without a query.
     *
     * @return DraftRow|null
     */
    public function find(string $id, string $sellerId): ?array
    {
        try {
            $listingId = ListingId::fromString($id);
        } catch (InvalidListingId) {
            return null;
        }

        if (null === ($seller = self::sellerId($sellerId))) {
            return null;
        }

        /** @var array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string, published_at: ?string, status: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.', status FROM listing_listing WHERE id = :id AND seller_id = :seller',
            ['id' => $listingId->toString(), 'seller' => $seller->toString()],
        );

        return false === $row ? null : self::toDraftRow($row);
    }

    /**
     * The listing, if it exists **and** is published; null otherwise — a draft, a withdrawn listing,
     * an unknown id, and a malformed id (answered without a query) all look the same (AC-23).
     *
     * @return PublicRow|null
     */
    public function findPublished(string $id): ?array
    {
        try {
            $listingId = ListingId::fromString($id);
        } catch (InvalidListingId) {
            return null;
        }

        // `published_at` is non-null for every published row (L-16, kept by the aggregate).
        /** @var array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string, published_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS." FROM listing_listing WHERE id = :id AND status = 'published'",
            ['id' => $listingId->toString()],
        );

        return false === $row ? null : self::toRow($row) + ['publishedAt' => self::isoDate($row['published_at'])];
    }

    /**
     * The draft's stored details as the edit form's prefill, keyed by attribute id: an option id, a
     * whole number as a string, `yes`/`no`, or the text — exactly what the form would submit.
     *
     * **No seller or status filter**: call it only with an id `find()` has already passed for this
     * seller, or that `findPublished()` has just returned.
     *
     * @return array<string, string>
     */
    public function valuesOf(string $listingId): array
    {
        /** @var array<string, string> $values */
        $values = $this->connection->fetchAllKeyValue(
            "SELECT attribute_id, COALESCE(option_id::text, value_int::text,
                    CASE WHEN value_bool THEN 'yes' WHEN NOT value_bool THEN 'no' END, value_text)
             FROM listing_attribute_value WHERE listing_id = :listing",
            ['listing' => $listingId],
        );

        return $values;
    }

    private static function sellerId(string $value): ?SellerId
    {
        try {
            return SellerId::fromString($value);
        } catch (InvalidSellerId) {
            return null;
        }
    }

    /**
     * @param array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string, published_at: ?string, status: string} $row
     *
     * @return DraftRow
     */
    private static function toDraftRow(array $row): array
    {
        return self::toRow($row) + [
            'status' => $row['status'],
            'publishedAt' => null === $row['published_at'] ? null : self::isoDate($row['published_at']),
        ];
    }

    private static function isoDate(string $timestamp): string
    {
        return new \DateTimeImmutable($timestamp)->format(\DATE_ATOM);
    }

    /**
     * @param array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string} $row
     *
     * @return array{id: string, categoryId: string, title: string, description: string, price: string, currency: string}
     */
    private static function toRow(array $row): array
    {
        $currency = Currency::fromCode($row['price_currency']);

        return [
            'id' => $row['id'],
            'categoryId' => $row['category_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'price' => Price::fromMinorUnits($row['price_amount'], $currency)->toDecimalString(),
            'currency' => $currency->value,
        ];
    }
}
