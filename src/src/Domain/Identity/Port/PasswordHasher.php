<?php

declare(strict_types=1);

namespace App\Domain\Identity\Port;

use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\PlainPassword;

/**
 * Turns the secret a human typed into the opaque thing we are willing to store.
 *
 * HASHING ONLY — there is deliberately no `verify()`. Per ADR-0005, credential *checking* happens
 * in the Symfony Security layer, which verifies the stored hash itself as part of authenticating a
 * request; a `verify()` here would have no caller and would be dead code pretending to be an API.
 * The signature is also the reason the Domain never holds plaintext at rest: `PlainPassword` goes
 * in, `HashedPassword` comes out, and the transient value is discarded with the handler's stack
 * frame.
 *
 * When `identity-password-reset` needs to re-hash, it reuses `hash()`. Nothing new is required.
 */
interface PasswordHasher
{
    public function hash(PlainPassword $password): HashedPassword;
}
