<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "this account now holds that role". Granted only by the operator from the console
 * (ADR-0005 amendment, plan D10). `$role` is the Symfony string (`ROLE_ADMIN`); the handler turns
 * it into a `Role`.
 */
final readonly class GrantRole
{
    public function __construct(
        public string $email,
        public string $role,
    ) {
    }
}
