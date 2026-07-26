<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\Role;

final class CannotRevokeBaseRole extends \DomainException
{
    public static function forRole(Role $role): self
    {
        return new self(\sprintf('%s is the base role every user holds and cannot be revoked.', $role->value));
    }
}
