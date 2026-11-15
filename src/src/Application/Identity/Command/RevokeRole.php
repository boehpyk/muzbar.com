<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "this account no longer holds that role". Takes effect on the account's next request,
 * when Symfony refreshes the user and sees the roles changed (AC-32).
 */
final readonly class RevokeRole
{
    public function __construct(
        public string $email,
        public string $role,
    ) {
    }
}
