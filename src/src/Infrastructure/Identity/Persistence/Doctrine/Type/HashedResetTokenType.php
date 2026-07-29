<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidHashedResetToken;
use App\Domain\Identity\ValueObject\HashedResetToken;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\StringType;

/**
 * Maps `HashedResetToken` onto a `VARCHAR(255)` column.
 *
 * WHY THE WRITE DIRECTION REFUSES BARE STRINGS — EVEN THOUGH THIS COLUMN *IS* QUERIED BY.
 *
 * The asymmetry is inherited from `HashedVerificationTokenType`, and it is worth restating rather
 * than cross-referencing because the stakes are one notch higher here. `UserIdType` and `EmailType`
 * accept strings because identifiers and addresses reach Doctrine from paths that do not preserve
 * the value object — `EntityManager::find()`, the schema tool's comparisons, a DQL parameter someone
 * bound by hand. `HashedPasswordType` refuses them, because *nothing queries by password hash*, so a
 * raw string arriving there could only be a mistake.
 *
 * This column is very much searched — `findByTokenHash()` is the hot query of the whole slice — but
 * "searched" is not "searched with a string". `DoctrinePasswordResetRequestRepository::findByTokenHash()`
 * binds a `HashedResetToken`, and Doctrine resolves the parameter's type from the field mapping and
 * hands *that object* to this method: the value object survives the whole round trip from port
 * signature to bound parameter, because the port's signature is a value object too. There is no path
 * in this codebase where a string legitimately arrives here.
 *
 * So accepting strings would buy nothing and would cost the guard `HashedPasswordType` exists for —
 * and the failure it guards against is worse here than anywhere else in the context.
 * `HashedResetToken` deliberately performs no format validation (a digest is opaque to the Domain),
 * so a plaintext 43-character `ResetToken` is a perfectly valid value for it. A permissive write
 * direction would accept the plaintext without a murmur, and the stored row would then be a
 * **working account-takeover URL** rather than a digest of one — strictly worse than slice 2's
 * version of the same mistake, where the leaked credential could only verify an address. Requiring
 * the value object closes that path at the type boundary instead of trusting review to catch it. The
 * rule to carry forward: a type accepts strings when a *framework* path forces it to, never merely
 * because the column is queried.
 *
 * SECOND, QUIETER RULE: no error raised here may echo a value that could be a plaintext token
 * (AC-10). Two of the three throws below get that for free — `InvalidHashedResetToken` carries only
 * the limit that was breached, and `ValueNotConvertible`'s message form, when handed a `$message`,
 * ignores the value entirely. The third does **not**, and it is the one that matters:
 * `InvalidType::new()` `var_export`s a *scalar* value straight into its message (it describes only
 * non-scalars by type). So the write direction — the exact path a mis-bound plaintext token would
 * take — would print that token into a log at the moment the guard above caught it, turning a
 * successful defence into the leak it was defending against. It is therefore handed a redacted
 * stand-in, matching the `'***'` that `ResetToken::__debugInfo()` already uses. The read direction's
 * `InvalidType` needs no such care: it fires only when the *database* returned a non-string for this
 * column, which is a corrupt row rather than a secret.
 */
final class HashedResetTokenType extends StringType
{
    public const string NAME = 'identity_reset_token_hash';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?HashedResetToken
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof HashedResetToken) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', HashedResetToken::class]);
        }

        try {
            return HashedResetToken::fromString($value);
        } catch (InvalidHashedResetToken $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof HashedResetToken) {
            // `'***'` rather than `$value` — see the class docblock's second rule. The expected-types
            // list still says exactly what was wrong, and `get_debug_type()` is appended so the
            // report stays diagnosable without being readable.
            throw InvalidType::new(\sprintf('*** (%s)', get_debug_type($value)), self::NAME, ['null', HashedResetToken::class]);
        }

        return $value->toString();
    }
}
