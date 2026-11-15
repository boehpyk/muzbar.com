<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidCategorySlug;
use App\Domain\Catalog\ValueObject\CategorySlug;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `CategorySlug` onto a `VARCHAR(64)` column (the `EmailType` shape). The width lives in the
 * mapping, the rule in the VO; this class only converts. Strings on the way down go through
 * `CategorySlug::fromString()`, so a bound parameter is normalised exactly as a stored slug was and
 * the partial unique indexes compare like with like.
 */
final class CategorySlugType extends StringType
{
    public const string NAME = 'catalog_category_slug';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CategorySlug
    {
        if (null === $value || $value instanceof CategorySlug) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategorySlug::class]);
        }

        try {
            return CategorySlug::fromString($value);
        } catch (InvalidCategorySlug $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof CategorySlug) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategorySlug::class]);
        }

        try {
            return CategorySlug::fromString($value)->toString();
        } catch (InvalidCategorySlug $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', CategorySlug::class], $e);
        }
    }
}
