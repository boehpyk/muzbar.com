<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidAttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `AttributeLabel` onto a `VARCHAR(100)` column, for attribute and option labels alike (the
 * `CategoryNameType` shape). The width lives in the mapping, the rule (C-9) in the VO; this class
 * only converts. A stored label that no longer parses was written around the aggregate and fails
 * loudly rather than reaching the admin screen.
 */
final class AttributeLabelType extends StringType
{
    public const string NAME = 'catalog_attribute_label';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?AttributeLabel
    {
        if (null === $value || $value instanceof AttributeLabel) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeLabel::class]);
        }

        try {
            return AttributeLabel::fromString($value);
        } catch (InvalidAttributeLabel $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof AttributeLabel) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeLabel::class]);
        }

        try {
            return AttributeLabel::fromString($value)->toString();
        } catch (InvalidAttributeLabel $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', AttributeLabel::class], $e);
        }
    }
}
