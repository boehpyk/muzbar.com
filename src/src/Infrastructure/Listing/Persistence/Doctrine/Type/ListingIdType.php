<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine\Type;

use App\Domain\Listing\Exception\InvalidListingId;
use App\Domain\Listing\ValueObject\ListingId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `ListingId` onto a Postgres `UUID` column (the `CategoryIdType` shape).
 *
 * A stored value the VO refuses is a row written around the aggregate and fails loudly on the way
 * up; a string on the way down goes through the VO, so a bound parameter is checked here rather
 * than by PostgreSQL with no PHP context.
 */
final class ListingIdType extends GuidType
{
    public const string NAME = 'listing_listing_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ListingId
    {
        if (null === $value || $value instanceof ListingId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingId::class]);
        }

        try {
            return ListingId::fromString($value);
        } catch (InvalidListingId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof ListingId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingId::class]);
        }

        try {
            return ListingId::fromString($value)->toString();
        } catch (InvalidListingId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingId::class], $e);
        }
    }
}
