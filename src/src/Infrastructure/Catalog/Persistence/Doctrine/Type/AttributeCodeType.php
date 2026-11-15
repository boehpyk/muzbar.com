<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidAttributeCode;
use App\Domain\Catalog\ValueObject\AttributeCode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `AttributeCode` onto a `VARCHAR(64)` column (the `CategorySlugType` shape). The width lives in
 * the mapping, the rule (C-10) in the VO; this class only converts. Strings on the way down go
 * through `AttributeCode::fromString()`, so a bound parameter is normalised exactly as a stored code
 * was and the `(category_id, code)` unique index compares like with like.
 */
final class AttributeCodeType extends StringType
{
    public const string NAME = 'catalog_attribute_code';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AttributeCode
    {
        if (null === $value || $value instanceof AttributeCode) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeCode::class]);
        }

        try {
            return AttributeCode::fromString($value);
        } catch (InvalidAttributeCode $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof AttributeCode) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeCode::class]);
        }

        try {
            return AttributeCode::fromString($value)->toString();
        } catch (InvalidAttributeCode $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeCode::class], $e);
        }
    }
}
