<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine\Type;

use App\Domain\Listing\Exception\InvalidListingTitle;
use App\Domain\Listing\ValueObject\ListingTitle;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `ListingTitle` onto a `VARCHAR(120)` column. The width lives in the mapping, the rule (L-3) in the VO.
 *
 * A stored value the VO refuses is a row written around the aggregate and fails loudly on the way
 * up; a string on the way down goes through the VO, so a bound parameter is checked here rather
 * than by PostgreSQL with no PHP context.
 */
final class ListingTitleType extends StringType
{
    public const string NAME = 'listing_title';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ListingTitle
    {
        if (null === $value || $value instanceof ListingTitle) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingTitle::class]);
        }

        try {
            return ListingTitle::fromString($value);
        } catch (InvalidListingTitle $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof ListingTitle) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingTitle::class]);
        }

        try {
            return ListingTitle::fromString($value)->toString();
        } catch (InvalidListingTitle $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', ListingTitle::class], $e);
        }
    }
}
