<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidAttributeOptionId;
use App\Domain\Catalog\ValueObject\AttributeOptionId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `AttributeOptionId` onto a Postgres `UUID` column — copy 7 of the `CategoryIdType` shape
 * (plan D12). Option order is `ORDER BY id` (plan D3), which works because Postgres compares `uuid`
 * bytewise and a UUIDv7 leads with its timestamp.
 *
 * A stored value that is not a valid id is a corrupted row and fails loudly on the way up; a string
 * on the way down is routed through `AttributeOptionId::fromString()` so a malformed id is rejected
 * here, not as a Postgres `invalid input syntax for type uuid` with no PHP context.
 */
final class AttributeOptionIdType extends GuidType
{
    public const string NAME = 'catalog_attribute_option_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AttributeOptionId
    {
        if (null === $value || $value instanceof AttributeOptionId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeOptionId::class]);
        }

        try {
            return AttributeOptionId::fromString($value);
        } catch (InvalidAttributeOptionId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof AttributeOptionId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeOptionId::class]);
        }

        try {
            return AttributeOptionId::fromString($value)->toString();
        } catch (InvalidAttributeOptionId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeOptionId::class], $e);
        }
    }
}
