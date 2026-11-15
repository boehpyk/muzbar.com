<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Persistence\Doctrine\Type;

use App\Domain\Catalog\Exception\InvalidNumberRules;
use App\Domain\Catalog\Exception\InvalidUnit;
use App\Domain\Catalog\ValueObject\NumberRules;
use App\Domain\Catalog\ValueObject\Unit;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonbType;

/**
 * Maps `NumberRules` onto a `JSONB` column as `{"unit": ?string, "min": ?int, "max": ?int}`
 * (ADR-0007: one VO, one column, one type; `jsonb` lives in the type, never in the mapping).
 *
 * WHY A BAD ROW IS FATAL (the `OAuthIdentitySetType` precedent, AC-28): a missing key, a wrong type,
 * an invalid unit or `min > max` means the row was written around the aggregate. Dropping the bad
 * part would hydrate an attribute with different rules than the ones stored; refusing to hydrate is
 * loud — a 500 and a Sentry event — and never a partial attribute. Messages never quote the data.
 */
final class NumberRulesType extends JsonbType
{
    public const string NAME = 'catalog_attribute_number_rules';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?NumberRules
    {
        $decoded = parent::convertToPHPValue($value, $platform);

        if (null === $decoded) {
            return null;
        }

        if (!\is_array($decoded)
            || 3 !== \count($decoded)
            || !\array_key_exists('unit', $decoded) || !(null === $decoded['unit'] || \is_string($decoded['unit']))
            || !\array_key_exists('min', $decoded) || !(null === $decoded['min'] || \is_int($decoded['min']))
            || !\array_key_exists('max', $decoded) || !(null === $decoded['max'] || \is_int($decoded['max']))
        ) {
            throw ValueNotConvertible::new($value, self::NAME, 'expected {"unit": ?string, "min": ?int, "max": ?int}.');
        }

        try {
            return NumberRules::of(
                null === $decoded['unit'] ? null : Unit::fromString($decoded['unit']),
                $decoded['min'],
                $decoded['max'],
            );
        } catch (InvalidUnit|InvalidNumberRules $e) {
            throw ValueNotConvertible::new($value, self::NAME, 'the stored rules break C-16.', $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof NumberRules) {
            throw InvalidType::new($value, self::NAME, ['null', NumberRules::class]);
        }

        return parent::convertToDatabaseValue([
            'unit' => $value->unit()?->toString(),
            'min' => $value->minimum(),
            'max' => $value->maximum(),
        ], $platform);
    }
}
