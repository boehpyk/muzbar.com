<?php

declare(strict_types=1);

namespace App\Application\Identity\Query;

/**
 * The question "somebody has opened a reset link carrying this token — is it good?".
 *
 * The context's **first query object**, and the reason it is a query rather than a command is the
 * whole design of the GET path: answering it must change nothing (AC-12). See
 * `CheckPasswordResetTokenHandler` for why the GET cannot burn the challenge and why mail-scanner
 * prefetch is what forces it.
 *
 * The token arrives as a raw `string` straight off the URL path, unvalidated. Same
 * primitives-not-value-objects rule as every command in this context, and here the payoff is sharp:
 * `ResetToken::fromString()` is the check that a malformed token can never reach a repository
 * lookup, and putting it in the handler is what makes that check *unavoidable* rather than something
 * the controller is trusted to have done. The route's `requirements` regex still rejects junk first
 * — the cheap gate that keeps a 10 kB path segment out of PHP logic — but the rule itself lives in
 * the value object, one layer deeper than anything an adapter can skip.
 *
 * `#[\SensitiveParameter]` is on the constructor parameter because this string **is** a live
 * credential until it is redeemed, and on this flow the credential is the account rather than a
 * mailbox claim. The attribute strips the argument from stack traces, which is the one place a
 * command's contents routinely end up in a log; it costs nothing and closes the most likely
 * accidental disclosure path (AC-10). The public property is deliberately still readable — the
 * handler has to read it.
 */
final readonly class CheckPasswordResetToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
