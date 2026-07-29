<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "burn this reset challenge and set this password on the account behind it".
 *
 * The end of the recovery flow and the only destructive step in it. Both fields arrive as raw
 * `string`s — the token off the session stash the GET put there (ADR-0011 decision 8), the password
 * off the form — and both are turned into value objects inside the handler, in a deliberate order
 * that the handler documents.
 *
 * **BOTH FIELDS CARRY `#[\SensitiveParameter]`, BECAUSE BOTH ARE LIVE CREDENTIALS.** The token owns
 * the account until it is redeemed; the password owns it afterwards, and — unlike the token — it is
 * very likely to be a password the person also uses somewhere else, so a copy of it in a stack trace
 * outlives this system entirely. The attribute strips both arguments from stack traces, which is the
 * one place a command's contents routinely end up in a log; it costs nothing and closes the most
 * likely accidental disclosure path (AC-10). The public properties are deliberately still readable —
 * the handler has to read them.
 *
 * There is no `email` field and there is deliberately no way to add one. The account is identified
 * **by the token**, which is the only thing that proves anything; taking an address alongside it
 * would create a second, weaker way to name the target and an obvious next question about what
 * happens when the two disagree.
 */
final readonly class ResetPasswordWithToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        #[\SensitiveParameter]
        public string $newPassword,
    ) {
    }
}
