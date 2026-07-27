<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidHashedVerificationToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `HashedVerificationToken` onto a `VARCHAR(255)` column.
 *
 * WHY THE WRITE DIRECTION REFUSES BARE STRINGS — EVEN THOUGH THIS COLUMN *IS* QUERIED BY.
 *
 * This is the one place where the two types this class was modelled on genuinely disagree, so the
 * choice is argued rather than copied. `UserIdType` and `EmailType` accept strings because
 * identifiers and addresses reach Doctrine from paths that do not preserve the value object —
 * `EntityManager::find()`, the schema tool's comparisons, a DQL parameter someone bound by hand.
 * `HashedPasswordType` refuses them, on the grounds that *nothing queries by password hash*, so a
 * raw string arriving there could only be a mistake — and the most expensive version of that
 * mistake is a plaintext password being handed to Doctrine and quietly stored as if it were a
 * digest.
 *
 * At first glance this type sits on the other side of that line: `findByTokenHash()` is the hot
 * query of the whole slice, so this column is very much searched. But "searched" is not the same
 * as "searched with a string". `DoctrineEmailVerificationRequestRepository::findByTokenHash()`
 * binds a `HashedVerificationToken`, and Doctrine resolves the parameter's type from the field
 * mapping and hands *that object* to this method — the value object survives the whole round trip
 * from port signature to bound parameter, because the port's signature is a value object too.
 * There is no path in this codebase where a string legitimately arrives here.
 *
 * So the question becomes: what would accepting strings buy, and what would it cost? It would buy
 * nothing, because no caller needs it. It would cost the exact guard `HashedPasswordType` exists
 * for, and the failure it guards against is *worse* here than there. `HashedVerificationToken`
 * deliberately performs no format validation (a digest is opaque to the Domain — see the value
 * object), so a plaintext 43-character token is a perfectly valid value for it. A permissive write
 * direction would therefore accept `$request->tokenHash = $plainToken` without a murmur, and the
 * result is the precise thing ADR-0009 decision 2 exists to prevent: a database dump that is a set
 * of working account-takeover URLs. Requiring the value object closes that path at the type
 * boundary instead of trusting review to catch it. The rule to carry forward is that a type accepts
 * strings when a *framework* path forces it to, never merely because the column is queried.
 *
 * SECOND, QUIETER RULE: no error raised here may echo a value that could be a plaintext token
 * (AC-2). Two of the three throws below get that for free — `InvalidHashedVerificationToken` carries
 * only the limit that was breached, and `ValueNotConvertible`'s message form, when handed a
 * `$message`, ignores the value entirely. The third does **not**, and it is the one that matters:
 * `InvalidType::new()` `var_export`s a *scalar* value straight into its message (it describes only
 * non-scalars by type). So the write direction — the exact path a mis-bound plaintext token would
 * take — would print that token into a log at the moment the guard above caught it, turning a
 * successful defence into the leak it was defending against. It is therefore handed a redacted
 * stand-in, matching the `'***'` that `VerificationToken::__debugInfo()` already uses. The read
 * direction's `InvalidType` needs no such care: it fires only when the *database* returned a
 * non-string for this column, which is a corrupt row rather than a secret.
 */
final class HashedVerificationTokenType extends StringType
{
    public const string NAME = 'identity_verification_token_hash';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?HashedVerificationToken
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof HashedVerificationToken) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', HashedVerificationToken::class]);
        }

        try {
            return HashedVerificationToken::fromString($value);
        } catch (InvalidHashedVerificationToken $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof HashedVerificationToken) {
            // `'***'` rather than `$value` — see the class docblock's second rule. The expected-types
            // list still says exactly what was wrong, and `get_debug_type()` is appended so the
            // report stays diagnosable without being readable.
            throw InvalidType::new(\sprintf('*** (%s)', get_debug_type($value)), self::NAME, ['null', HashedVerificationToken::class]);
        }

        return $value->toString();
    }
}
