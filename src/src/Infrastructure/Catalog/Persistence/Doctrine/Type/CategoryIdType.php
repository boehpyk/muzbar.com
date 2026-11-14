<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidCategoryId;
use App\Domain\Catalog\ValueObject\CategoryId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `CategoryId` onto a Postgres `UUID` column — a copy of `UserIdType`'s shape, as
 * `CategoryId` is a copy of `UserId`'s (plan D8). Used for both `id` and `parent_id`, so a parent
 * reference round-trips a real `CategoryId` without being an association (ADR-0009).
 *
 * A stored value that is not a valid id is a corrupted row and fails loudly on the way up; a string
 * on the way down is routed through `CategoryId::fromString()` so a malformed id is rejected here,
 * not as a Postgres `invalid input syntax for type uuid` with no PHP context.
 */
final class CategoryIdType extends GuidType
{
    public const string NAME = 'catalog_category_id';

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
