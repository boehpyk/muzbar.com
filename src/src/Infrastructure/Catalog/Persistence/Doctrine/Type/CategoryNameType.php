<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidCategoryName;
use App\Domain\Catalog\ValueObject\CategoryName;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `CategoryName` onto a `VARCHAR(100)` column (the `EmailType` shape). The width lives in the
 * mapping, the rule (C-1) in the VO; this class only converts. A stored name that no longer parses
 * was written around the aggregate and fails loudly rather than reaching the admin screen.
 */
final class CategoryNameType extends StringType
{
    public const string NAME = 'catalog_category_name';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CategoryName
    {
        if (null === $value || $value instanceof CategoryName) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryName::class]);
        }

        try {
            return CategoryName::fromString($value);
        } catch (InvalidCategoryName $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CategoryName) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryName::class]);
        }

        try {
            return CategoryName::fromString($value)->toString();
        } catch (InvalidCategoryName $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategoryName::class], $e);
        }
    }
}
