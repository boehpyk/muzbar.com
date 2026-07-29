<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

/**
 * A well-formed token was presented that matches no request the system ever issued.
 *
 * **The factory takes no argument, and that is the whole design of this class.** Every other
 * `…NotFound` in the context names what was looked for — `UserNotFound::withEmail()`, `::withId()` —
 * because those handles are useful in a log and grant nobody anything. A token is different in kind:
 * it *is* the credential, and on this flow it is the credential that owns the account. A message
 * reading `No request found for token "abc…"` would write live account-takeover secrets into every
 * log sink the moment a mail client mangled a link, and the one case where the token is genuinely
 * worthless — this one, where it matches nothing — is indistinguishable at the call site from the
 * cases where it is not.
 *
 * So there is **no signature through which a token can enter a message** (AC-10). The diagnostic
 * value lost is close to zero: "an unissued token was presented" is the entire fact, and the request
 * id one would want to correlate against does not exist by definition.
 *
 * To the visitor this is indistinguishable from an expired, superseded, spent or malformed link
 * (AC-16) — one response, one flash string, one status code. It stays a distinct exception because
 * the *system* should be able to tell them apart in a log and in a test; collapsing them at the
 * domain level would throw information away to achieve a presentation goal, which is the wrong layer
 * to achieve it in.
 */
final class PasswordResetRequestNotFound extends \DomainException
{
    public static function forToken(): self
    {
        return new self('No password reset request matches the presented token.');
    }
}
