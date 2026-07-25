<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Persistence\Doctrine\Type;

use App\Domain\Identity\ValueObject\Role;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps `list<Role>` onto a `JSON` column, storing the enum's backed values.
 *
 * WHY JSON RATHER THAN A `identity_user_role` JOIN TABLE.
 * Roles are a tiny, closed, always-loaded-with-the-user set with no attributes of their own. A
 * join table would add a query (or a fetch-join) to every single authentication, and would model
 * a relationship where there is only a property. Postgres `JSON` keeps the aggregate a single row,
 * which is exactly what an aggregate boundary is supposed to be. The day a role grows an
 * expiry date or a granting actor, it stops being a value and earns its own table — and that will
 * be a visible, deliberate migration rather than a slow drift.
 *
 * WHY BACKED VALUES AND NOT CASE NAMES: `ROLE_USER` is what Symfony Security reads and what a DBA
 * inspecting the row expects to see. Storing `User` would make the column meaningless outside PHP.
 *
 * WHY AN UNKNOWN ROLE STRING IS FATAL: `Role::tryFrom()` returning null means the database holds
 * an authorisation level this build does not recognise. Skipping it would silently *downgrade* a
 * user's permissions; guessing at it would silently *invent* them. Refusing to hydrate is the only
 * answer that cannot quietly become a security incident.
 */
final class RoleSetType extends JsonType
{
    public const string NAME = 'identity_role_set';

    /**
     * @return list<Role>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $decoded = parent::convertToPHPValue($value, $platform);

        if (null === $decoded) {
            return null;
        }

        if (!\is_array($decoded)) {
            throw ValueNotConvertible::new($value, self::NAME, 'expected a JSON array of role identifiers.');
        }

        $roles = [];

        foreach ($decoded as $stored) {
            if (!\is_string($stored)) {
                throw ValueNotConvertible::new($value, self::NAME, \sprintf('expected role identifiers to be strings, got %s.', get_debug_type($stored)));
            }

            $role = Role::tryFrom($stored);

            if (null === $role) {
                throw ValueNotConvertible::new($value, self::NAME, \sprintf('"%s" is not a known role.', $stored));
            }

            $roles[] = $role;
        }

        return $roles;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'list<'.Role::class.'>']);
        }

        $stored = [];

        foreach ($value as $role) {
            if (!$role instanceof Role) {
                throw InvalidType::new($role, self::NAME, ['null', 'list<'.Role::class.'>']);
            }

            $stored[] = $role->value;
        }

        // `$stored` is appended to, never keyed, so it is always a list and `json_encode` always
        // emits `["ROLE_USER"]` rather than `{"1":"ROLE_USER"}`. That gapped-key trap is the
        // reason `User::revokeRole()` re-indexes with `array_values` on its side of the boundary.
        return parent::convertToDatabaseValue($stored, $platform);
    }
}
