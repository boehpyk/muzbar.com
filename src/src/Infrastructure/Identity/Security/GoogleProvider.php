<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use League\OAuth2\Client\Provider\Google;

/**
 * league's Google provider, minus the `profile` scope.
 *
 * `Google::getAuthorizationParameters()` *merges* its default scopes (`openid email profile`) into
 * whatever the caller passes, so `redirect(['openid', 'email'])` alone still asks for `profile`.
 * We never read a name or a photo, and data we do not use is data we would have to protect — so the
 * consent screen asks for exactly `openid email` (AC-1). This override is the only way to say so.
 */
final class GoogleProvider extends Google
{
    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        // "openid" must stay first for OpenID Connect.
        return ['openid', 'email'];
    }
}
