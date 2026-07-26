<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "create an account from this email and this password".
 *
 * COMMANDS CARRY PRIMITIVES, NOT VALUE OBJECTS. It looks like a missed opportunity to type things
 * properly, and it is the opposite: if the command demanded an `Email`, then whoever built the
 * command would be the one enforcing the domain rules, and every new adapter would have to be
 * trusted to do it correctly. By taking raw strings, the *handler* builds the value objects, so
 * the invariants hold identically whether the command came from the HTTP form or the console
 * today, or from the OAuth callback and the verification link in later slices.
 *
 * The Infrastructure boundary still validates first — but for a different job. The form validates
 * for message quality and UX ("this email looks wrong", shown next to the field); the value object
 * validates for correctness (an invalid `Email` cannot exist). Two layers, two purposes, and the
 * handler tests prove the second one works with the first one absent.
 */
final readonly class RegisterUser
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}
