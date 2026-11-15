<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidAttributeId;
use App\Domain\Catalog\ValueObject\AttributeId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `AttributeId` onto a Postgres `UUID` column — copy 6 of the `CategoryIdType` shape (plan D12).
 * Used for `catalog_attribute.id` and `catalog_attribute_option.attribute_id`, so an option's owner
 * round-trips a real `AttributeId` without being an association (ADR-0009, ADR-0017).
 *
 * A stored value that is not a valid id is a corrupted row and fails loudly on the way up; a string
 * on the way down is routed through `AttributeId::fromString()` so a malformed id is rejected here,
 * not as a Postgres `invalid input syntax for type uuid` with no PHP context.
 */
final class AttributeIdType extends GuidType
{
    public const string NAME = 'catalog_attribute_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AttributeId
    {
        if (null === $value || $value instanceof AttributeId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeId::class]);
        }

        try {
            return AttributeId::fromString($value);
        } catch (InvalidAttributeId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof AttributeId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeId::class]);
        }

        try {
            return AttributeId::fromString($value)->toString();
        } catch (InvalidAttributeId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeId::class], $e);
        }
    }
}
