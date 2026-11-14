<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

/**
 * An external identity provider a `User` can sign in through (ADR-0005, ADR-0014).
 *
 * A closed set, like `Role`: one case today. The backed value is what `oauth_identities` stores, so
 * it is a persisted name and must never be renamed once a row holds it.
 */
enum OAuthProvider: string
{
    case Google = 'google';
}
