<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\ValueObject\Email;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `Email` onto a `VARCHAR(180)` column.
 *
 * The width is not repeated here — the mapping XML quotes `Email::MAX_LENGTH`'s value and the VO
 * owns the rule. This class only converts.
 *
 * WHY IT MATTERS THAT STRINGS GO THROUGH `Email::fromString()` ON THE WAY DOWN: the VO
 * lower-cases and trims. A query parameter bound as a raw string would otherwise search for
 * `Max@Example.com` in a column that only ever stores `max@example.com`, and the unique index
 * would silently stop matching. Normalising in both directions is what makes `existsByEmail()`
 * case-insensitive without a single `LOWER()` in the SQL.
 */
final class EmailType extends StringType
{
    public const string NAME = 'identity_email';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Email
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Email) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Email::class]);
        }

        try {
            return Email::fromString($value);
        } catch (InvalidEmail $e) {
            // A stored address that no longer parses means the row was written around the
            // aggregate. Failing here beats handing the application an unusable User.
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Email) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Email::class]);
        }

        try {
            return Email::fromString($value)->toString();
        } catch (InvalidEmail $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Email::class], $e);
        }
    }
}
