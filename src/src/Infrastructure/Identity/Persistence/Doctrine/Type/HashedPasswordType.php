<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidHashedPassword;
use App\Domain\Identity\ValueObject\HashedPassword;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `HashedPassword` onto a `VARCHAR(255)` column.
 *
 * WHY THE WRITE DIRECTION REFUSES BARE STRINGS, UNLIKE THE OTHER TYPES IN THIS DIRECTORY.
 * `UserIdType` and `EmailType` accept strings because ids and addresses are legitimately bound as
 * query parameters. Nothing in this system queries by password hash — a hash is written and read,
 * never searched — so the only way a raw string could arrive here is a mistake, and the most
 * expensive version of that mistake is a plaintext password being handed to Doctrine and stored.
 * Requiring the value object closes that path at the type boundary rather than trusting review.
 *
 * SECOND, QUIETER RULE: no error raised here ever echoes the value. The domain exceptions carry
 * only the limit that was breached, and `ValueNotConvertible`'s message form ignores the value it
 * is given, so a hash never reaches a log or a stack trace through this class.
 */
final class HashedPasswordType extends StringType
{
    public const string NAME = 'identity_password_hash';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?HashedPassword
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof HashedPassword) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', HashedPassword::class]);
        }

        try {
            return HashedPassword::fromString($value);
        } catch (InvalidHashedPassword $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof HashedPassword) {
            throw InvalidType::new($value, self::NAME, ['null', HashedPassword::class]);
        }

        return $value->toString();
    }
}
