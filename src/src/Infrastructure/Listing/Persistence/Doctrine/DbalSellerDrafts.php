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
 * @phpstan-type DraftRow array{id: string, categoryId: string, title: string, description: string, price: string, currency: string}
 */
final readonly class DbalSellerDrafts
{
    private const string COLUMNS = 'id, category_id, title, description, price_amount, price_currency';

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

        /** @var list<array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM listing_listing WHERE seller_id = :seller ORDER BY id DESC',
            ['seller' => $seller->toString()],
        );

        return array_map(self::toRow(...), $rows);
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

        /** @var array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM listing_listing WHERE id = :id AND seller_id = :seller',
            ['id' => $listingId->toString(), 'seller' => $seller->toString()],
        );

        return false === $row ? null : self::toRow($row);
    }

    /**
     * The draft's stored details as the edit form's prefill, keyed by attribute id: an option id, a
     * whole number as a string, `yes`/`no`, or the text — exactly what the form would submit.
     *
     * **No seller filter**: call it only with an id `find()` has already passed for this seller.
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
     * @param array{id: string, category_id: string, title: string, description: string, price_amount: int, price_currency: string} $row
     *
     * @return DraftRow
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
