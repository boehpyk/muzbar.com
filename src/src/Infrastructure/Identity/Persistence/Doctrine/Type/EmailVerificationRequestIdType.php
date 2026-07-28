<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidEmailVerificationRequestId;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\GuidType;

/**
 * Maps `EmailVerificationRequestId` onto a Postgres `UUID` column.
 *
 * Extending `GuidType` rather than `StringType` is what makes the column a real `UUID` instead of
 * a `VARCHAR(36)`: Postgres stores a UUID in 16 bytes, indexes it as an integer-like value, and
 * refuses malformed input at the storage layer. The conversion methods below are the only thing
 * this class adds.
 *
 * This is `UserIdType` with two identifiers changed, and that near-duplication is the *same*
 * knowing choice the value objects made (ADR-0009, Consequences): two examples are a coincidence,
 * three are a pattern, and a shared `AbstractUuidType` abstracted from two tends to fit neither.
 * The third arrives with `Catalog`; extracting a base then will be an observation rather than a
 * prediction. What is worth noticing meanwhile is that the duplication is *mechanical* — the two
 * classes differ only in the value object they build and the exception they catch — which is
 * exactly the shape that stays cheap to keep and cheap to collapse later.
 *
 * NOTE ON DBAL 4: `getName()` and `requiresSQLCommentHint()` were removed from `Type` — types are
 * now identified purely by the name they are registered under (see `doctrine.dbal.types`), and
 * the comment hint that used to disambiguate custom types in the schema diff is gone with it.
 * The `NAME` constant below is a convenience for error messages and for the mapping, not an
 * override of anything.
 */
final class EmailVerificationRequestIdType extends GuidType
{
    public const string NAME = 'identity_email_verification_request_id';

    /**
     * A row that cannot become a valid `EmailVerificationRequestId` is a corrupted row, and the
     * only safe response is to fail loudly. Returning `null`, or a half-built object, would let a
     * broken primary key travel into the aggregate and surface somewhere far away from the cause.
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?EmailVerificationRequestId
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof EmailVerificationRequestId) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', EmailVerificationRequestId::class]);
        }

        try {
            return EmailVerificationRequestId::fromString($value);
        } catch (InvalidEmailVerificationRequestId $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    /**
     * Strings are accepted as well as value objects because Doctrine binds identifiers from
     * several places (`find()`, DQL parameters, the schema tool's own comparisons) and not all of
     * them hand back the object they were given. Routing the string through
     * `EmailVerificationRequestId::fromString()` rather than passing it straight down means a
     * malformed id is rejected here instead of becoming a Postgres
     * `invalid input syntax for type uuid` error with no PHP context.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof EmailVerificationRequestId) {
            return $value->toString();
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', EmailVerificationRequestId::class]);
        }

        try {
            return EmailVerificationRequestId::fromString($value)->toString();
        } catch (InvalidEmailVerificationRequestId $e) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', EmailVerificationRequestId::class], $e);
        }
    }
}
