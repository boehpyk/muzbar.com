<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\Exception\InvalidOAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonbType;

/**
 * Maps `list<OAuthIdentity>` onto a `JSONB` column as `[{"provider":"google","subject":"…"}]`
 * (ADR-0014). Same reasoning as `RoleSetType` for JSON-not-a-join-table and for `jsonb` living in
 * the type rather than the mapping.
 *
 * THE ONE ENCODER. `DoctrineUserRepository::findByOAuthIdentity()` builds its `@>` containment
 * needle through this type too, so the shape written and the shape searched for cannot drift apart:
 * a hand-written needle that spelled a key differently would match nothing, silently, and every
 * returning Google user would be "new" and land on the link-by-email path instead.
 *
 * WHY A BAD ROW IS FATAL: an unknown provider or an invalid subject means the database holds an
 * identity this build cannot reason about. Dropping it would silently unlink a sign-in method;
 * refusing to hydrate is loud and cannot become a security incident. Error messages never quote the
 * subject — it is a personal identifier.
 */
final class OAuthIdentitySetType extends JsonbType
{
    public const string NAME = 'identity_oauth_identity_set';

    /**
     * @return list<OAuthIdentity>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $decoded = parent::convertToPHPValue($value, $platform);

        if (null === $decoded) {
            return null;
        }

        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw ValueNotConvertible::new($value, self::NAME, 'expected a JSON array of identities.');
        }

        $identities = [];

        foreach ($decoded as $stored) {
            if (!\is_array($stored) || !\is_string($stored['provider'] ?? null) || !\is_string($stored['subject'] ?? null)) {
                throw ValueNotConvertible::new($value, self::NAME, 'expected {"provider": string, "subject": string}.');
            }

            $provider = OAuthProvider::tryFrom($stored['provider']);

            if (null === $provider) {
                throw ValueNotConvertible::new($value, self::NAME, \sprintf('"%s" is not a known provider.', $stored['provider']));
            }

            try {
                // `OAuthIdentity::of()` enforces non-empty and `MAX_SUBJECT_LENGTH`.
                $identities[] = OAuthIdentity::of($provider, $stored['subject']);
            } catch (InvalidOAuthIdentity $e) {
                throw ValueNotConvertible::new($value, self::NAME, 'invalid subject.', $e);
            }
        }

        return $identities;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'list<'.OAuthIdentity::class.'>']);
        }

        $stored = [];

        foreach ($value as $identity) {
            if (!$identity instanceof OAuthIdentity) {
                throw InvalidType::new($identity, self::NAME, ['null', 'list<'.OAuthIdentity::class.'>']);
            }

            $stored[] = ['provider' => $identity->provider()->value, 'subject' => $identity->subject()];
        }

        return parent::convertToDatabaseValue($stored, $platform);
    }
}
