<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\OAuthProvider;

/**
 * The provider did not vouch for the address it asserted, so it cannot be used to link to or create
 * an account (AC-9). A domain rule even though the boolean arrives from outside: "we only trust a
 * provider-verified address" is what makes linking-by-email safe at all.
 *
 * Raised only on the link/provision paths. A subject that is already linked signs in regardless,
 * because the subject — not the email — is the identifier.
 *
 * The message names the provider, never the address.
 */
final class OAuthEmailNotVerified extends \DomainException
{
    public static function fromProvider(OAuthProvider $provider): self
    {
        return new self(\sprintf('Provider "%s" did not verify the asserted email address.', $provider->value));
    }
}
