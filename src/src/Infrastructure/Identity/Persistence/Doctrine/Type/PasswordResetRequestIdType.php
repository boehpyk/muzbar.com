<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidPasswordResetRequestId;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `PasswordResetRequestId` onto a Postgres `UUID` column.
 *
 * Extending `GuidType` rather than `StringType` is what makes the column a real `UUID` instead of
 * a `VARCHAR(36)`: Postgres stores a UUID in 16 bytes, indexes it as an integer-like value, and
 * refuses malformed input at the storage layer. The conversion methods below are the only thing
 * this class adds.
 *
 * THE THIRD OF THESE, AND STILL NOT A BASE CLASS. This is `EmailVerificationRequestIdType` with two
 * identifiers changed, which is exactly what `UserIdType` was before it. ADR-0009's consequence set
 * the trigger at *"two is a coincidence, three is a pattern — revisit when `Catalog` produces the
 * third example"*, and this file is the third example arriving early and from **inside `Identity`**.
 * The trigger is therefore restated rather than fired (ADR-0011 decision 9, technical plan
 * §*Reuse vs duplication*): an abstraction induced from three samples of one bounded context is an
 * `Identity` abstraction wearing a `Shared/` namespace, and `Catalog` was named precisely because it
 * is a *different* context and so is the thing that actually tests whether the commonality is "an
 * aggregate identity" or "how `Identity` happens to spell things".
 *
 * The duplication stays cheap for the same reason it did last time: it is **mechanical**. The three
 * classes differ only in the value object they build and the exception they catch, which is the
 * shape that is cheap to keep and cheap to collapse the day the extraction is an observation rather
 * than a prediction.
 *
 * NOTE ON DBAL 4: `getName()` and `requiresSQLCommentHint()` were removed from `Type` — types are
 * now identified purely by the name they are registered under (see `doctrine.dbal.types`), and
 * the comment hint that used to disambiguate custom types in the schema diff is gone with it.
 * The `NAME` constant below is a convenience for error messages and for the mapping, not an
 * override of anything.
 */
final class PasswordResetRequestIdType extends GuidType
{
    public const string NAME = 'identity_password_reset_request_id';

    /**
     * A row that cannot become a valid `PasswordResetRequestId` is a corrupted row, and the only
     * safe response is to fail loudly. Returning `null`, or a half-built object, would let a broken
     * primary key travel into the aggregate and surface somewhere far away from the cause.
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?PasswordResetRequestId
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof PasswordResetRequestId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', PasswordResetRequestId::class]);
        }

        try {
            return PasswordResetRequestId::fromString($value);
        } catch (InvalidPasswordResetRequestId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    /**
     * Strings are accepted as well as value objects because Doctrine binds identifiers from
     * several places (`find()`, DQL parameters, the schema tool's own comparisons) and not all of
     * them hand back the object they were given. Routing the string through
     * `PasswordResetRequestId::fromString()` rather than passing it straight down means a malformed
     * id is rejected here instead of becoming a Postgres `invalid input syntax for type uuid` error
     * with no PHP context.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof PasswordResetRequestId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', PasswordResetRequestId::class]);
        }

        try {
            return PasswordResetRequestId::fromString($value)->toString();
        } catch (InvalidPasswordResetRequestId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', PasswordResetRequestId::class], $e);
        }
    }
}
