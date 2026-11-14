<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "sign in the person this provider vouches for" — recognise, link or provision.
 *
 * Primitives, like every command here (see `RegisterUser`): the handler builds the value objects,
 * so the rules hold whatever adapter sent this. The authenticator (Infrastructure) does the OAuth
 * protocol and hands over plain claims; nothing Google-specific crosses into this layer.
 *
 * `$emailVerified` must be `true` only when the provider said exactly `true` — the strict reading is
 * the adapter's job, because only it sees the raw claim.
 */
final readonly class SignInWithOAuth
{
    /**
     * @param string $provider an `OAuthProvider` backed value
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
        public bool $emailVerified,
    ) {
    }
}
