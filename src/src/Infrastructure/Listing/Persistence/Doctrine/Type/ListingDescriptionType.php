<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine\Type;

use App\Domain\Listing\Exception\InvalidListingDescription;
use App\Domain\Listing\ValueObject\ListingDescription;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `ListingDescription` onto a `VARCHAR(5000)` column. The width lives in the mapping, the rule (L-4) in the VO.
 *
 * A stored value the VO refuses is a row written around the aggregate and fails loudly on the way
 * up; a string on the way down goes through the VO, so a bound parameter is checked here rather
 * than by PostgreSQL with no PHP context.
 */
final class ListingDescriptionType extends StringType
{
    public const string NAME = 'listing_description';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ListingDescription
    {
        if (null === $value || $value instanceof ListingDescription) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingDescription::class]);
        }

        try {
            return ListingDescription::fromString($value);
        } catch (InvalidListingDescription $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof ListingDescription) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingDescription::class]);
        }

        try {
            return ListingDescription::fromString($value)->toString();
        } catch (InvalidListingDescription $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingDescription::class], $e);
        }
    }
}
