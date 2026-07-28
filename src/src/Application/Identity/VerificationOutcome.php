<?php

declare(strict_types=1);

namespace App\Application\Identity;

/**
 * What `VerifyEmailWithTokenHandler` did, for the benefit of the adapter that called it.
 *
 * WHY THIS LIVES IN `Application` AND NOT IN `Domain`. Everything else the flow touches — `User`,
 * `EmailVerificationRequest`, `VerificationToken`, `EmailAlreadyVerified` — is a word a domain
 * expert would say out loud. "Verification outcome" is not. Nobody in the business thinks of
 * *verified* and *already verified* as two members of a set; they think of an address as verified or
 * not, which is `User::isEmailVerified()`, and that fact already lives in the Domain where it
 * belongs. This enum exists because a *use case* has a caller, and that caller needs to know which
 * of two paths the use case took **without asking a second question** — and "a value returned to the
 * caller so it need not re-query" is an Application concern by definition. Put it in `Domain` and
 * you have added a concept to the model that the model does not have, which is the quiet way a
 * ubiquitous language starts drifting.
 *
 * `RegisterUserHandler` set the precedent: it returns a `UserId`, which is the one fact its caller
 * cannot otherwise obtain, and nothing more. Same rule here. The controller maps the two cases onto
 * two flash messages; a hypothetical console adapter would map them onto two exit lines. Neither
 * needs a query, and neither has to reason about *why* the answer is what it is.
 *
 * Two cases, not three: there is no `Failed`. Every failure is an exception, because in each of those
 * cases the domain genuinely could not proceed, and an enum case would invite a caller to ignore the
 * return value and carry on. A backed enum rather than a pure one so the values can be logged or
 * carried in a URL without a `match` at the boundary; the case names are TitleCase per CLAUDE.md.
 */
enum VerificationOutcome: string
{
    /** The address was unverified, the token was good, and this call is what verified it. */
    case Verified = 'verified';

    /**
     * The account was already verified before this call, so nothing was mutated and nothing was
     * dispatched. Almost always a replay of a link a mail scanner already fetched — see the
     * short-circuit in `VerifyEmailWithTokenHandler`.
     */
    case AlreadyVerified = 'already_verified';
}
