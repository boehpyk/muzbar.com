<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "record that this address has been proven reachable".
 *
 * Keyed by email rather than by `UserId` because both of its adapters identify the account that
 * way: the operator typing `muzbar:identity:verify-email <email>` today, and the signed
 * verification link in `identity-email-verification` tomorrow. Same primitives-only reasoning as
 * `RegisterUser` — the handler builds the `Email`.
 */
final readonly class VerifyUserEmail
{
    public function __construct(
        public string $email,
    ) {
    }
}
