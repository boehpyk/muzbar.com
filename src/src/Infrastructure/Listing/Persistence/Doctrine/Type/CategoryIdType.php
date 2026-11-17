<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Persistence\Doctrine\Type;

use App\Domain\Listing\Exception\InvalidCategoryId;
use App\Domain\Listing\ValueObject\CategoryId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps Listing's `CategoryId` (an id the Catalog context owns) onto a `UUID` column. No FK (ADR-0009 d.4).
 *
 * A stored value the VO refuses is a row written around the aggregate and fails loudly on the way
 * up; a string on the way down goes through the VO, so a bound parameter is checked here rather
 * than by PostgreSQL with no PHP context.
 */
final class CategoryIdType extends GuidType
{
    public const string NAME = 'listing_category_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CategoryId
    {
        if (null === $value || $value instanceof CategoryId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryId::class]);
        }

        try {
            return CategoryId::fromString($value);
        } catch (InvalidCategoryId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CategoryId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryId::class]);
        }

        try {
            return CategoryId::fromString($value)->toString();
        } catch (InvalidCategoryId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryId::class], $e);
        }
    }
}
