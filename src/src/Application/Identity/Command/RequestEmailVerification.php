<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

/**
 * The intent "ask the owner of this address to prove it is theirs".
 *
 * One field, raw and un-normalised: the handler builds the `Email`, for the reason `RegisterUser`
 * states at length. Typing the field as `Email` would move the enforcement of the address rules
 * into whoever *constructs* the command, and this command has three constructors already — the
 * listener that fires on `UserRegistered`, the anonymous resend form, and the integration tests —
 * with `identity-google-oauth` due to add a fourth. Rules enforced once in the handler hold for all
 * of them; rules enforced by convention at each call site hold until somebody is in a hurry.
 *
 * There is no `#[\SensitiveParameter]` here, unlike `RegisterUser::$plainPassword`: an email
 * address is personal data but it is not a credential, and marking everything sensitive is how a
 * marker stops meaning anything. The token this command *causes* to exist never travels through a
 * command at all — it is minted inside the handler and handed straight to the mailer port.
 */
final readonly class RequestEmailVerification
{
    public function __construct(
        public string $email,
    ) {
    }
}
