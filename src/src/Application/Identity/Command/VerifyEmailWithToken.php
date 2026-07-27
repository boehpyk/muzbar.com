<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "somebody clicked a verification link carrying this token".
 *
 * The token arrives as a raw `string` straight off the URL path, unvalidated. Same
 * primitives-not-value-objects rule as `RegisterUser` and for the same reason, but here the payoff
 * is sharper than usual: `VerificationToken::fromString()` is the check that a malformed token can
 * never reach a repository lookup, and putting it in the handler is what makes that check
 * *unavoidable* rather than something the controller is trusted to have done. The route's
 * `requirements` regex still rejects junk first — it is the cheap gate that keeps a 10 kB path
 * segment out of PHP logic — but the rule itself lives in the value object, one layer deeper than
 * anything an adapter can skip.
 *
 * `#[\SensitiveParameter]` is on the constructor parameter because this string *is* a live
 * credential until it is redeemed. The attribute strips the argument from stack traces, which is the
 * only place a command's contents routinely end up in a log; it costs nothing and it closes the most
 * likely accidental disclosure path (AC-2). The public property is deliberately still readable —
 * the handler has to read it.
 */
final readonly class VerifyEmailWithToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
