<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "somebody who cannot get into this account is asking for a way back in".
 *
 * One field, raw and un-normalised: the handler builds the `Email`, for the reason `RegisterUser`
 * states at length and `RequestEmailVerification` repeats. Typing the field as `Email` would move
 * enforcement of the address rules into whoever *constructs* the command, and the invariants must
 * hold no matter which adapter dispatches it — the anonymous forgot-password form today, an admin
 * action or a support tool tomorrow. Rules enforced once in the handler hold for all of them; rules
 * enforced by convention at each call site hold until somebody is in a hurry.
 *
 * There is no `#[\SensitiveParameter]` here, unlike on `ResetPasswordWithToken`'s two fields: an
 * email address is personal data but it is not a credential, and marking everything sensitive is how
 * a marker stops meaning anything. The token this command *causes* to exist never travels through a
 * command at all — it is minted inside the handler and handed straight to the mailer port, which is
 * the containment the whole flow depends on.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        public string $email,
    ) {
    }
}
