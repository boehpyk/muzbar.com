<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidUserId;
use App\Domain\Identity\ValueObject\UserId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `UserId` onto a Postgres `UUID` column.
 *
 * Extending `GuidType` rather than `StringType` is what makes the column a real `UUID` instead of
 * a `VARCHAR(36)`: Postgres stores a UUID in 16 bytes, indexes it as an integer-like value, and
 * refuses malformed input at the storage layer. The conversion methods below are the only thing
 * this class adds.
 *
 * NOTE ON DBAL 4: `getName()` and `requiresSQLCommentHint()` were removed from `Type` — types are
 * now identified purely by the name they are registered under (see `doctrine.dbal.types`), and
 * the comment hint that used to disambiguate custom types in the schema diff is gone with it.
 * The `NAME` constant below is a convenience for error messages and for the mapping, not an
 * override of anything.
 */
final class UserIdType extends GuidType
{
    public const string NAME = 'identity_user_id';

    /**
     * A row that cannot become a valid `UserId` is a corrupted row, and the only safe response is
     * to fail loudly. Returning `null`, or a half-built object, would let a broken primary key
     * travel into the aggregate and surface somewhere far away from the cause.
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?UserId
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof UserId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', UserId::class]);
        }

        try {
            return UserId::fromString($value);
        } catch (InvalidUserId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    /**
     * Strings are accepted as well as value objects because Doctrine binds identifiers from
     * several places (`find()`, DQL parameters, the schema tool's own comparisons) and not all of
     * them hand back the object they were given. Routing the string through `UserId::fromString()`
     * rather than passing it straight down means a malformed id is rejected here instead of
     * becoming a Postgres `invalid input syntax for type uuid` error with no PHP context.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof UserId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', UserId::class]);
        }

        try {
            return UserId::fromString($value)->toString();
        } catch (InvalidUserId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', UserId::class], $e);
        }
    }
}
