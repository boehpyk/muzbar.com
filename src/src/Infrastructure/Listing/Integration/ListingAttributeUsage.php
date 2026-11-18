<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Integration;

use App\Domain\Catalog\Port\AttributeUsage;
use App\Domain\Catalog\ValueObject\AttributeId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use Doctrine\DBAL\Connection;

/**
 * Catalog's view of which attributes and options listings use (ADR-0020 decision 6) — the second
 * Catalog port implemented here, with the data's owner: `listing_attribute_value` is read only
 * under `Infrastructure/Listing`.
 *
 * On the EntityManager's connection, so inside `CategoryTreeLock::exclusively()` it reads in the
 * lock's transaction. Both queries are served by `idx_listing_attribute_value_option`.
 */
final readonly class ListingAttributeUsage implements AttributeUsage
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function isInUse(AttributeId $attributeId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM listing_attribute_value WHERE attribute_id = :attribute)',
            ['attribute' => $attributeId->toString()],
        );
    }

    public function isOptionInUse(AttributeId $attributeId, AttributeOptionId $optionId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM listing_attribute_value WHERE attribute_id = :attribute AND option_id = :option)',
            ['attribute' => $attributeId->toString(), 'option' => $optionId->toString()],
        );
    }
}
