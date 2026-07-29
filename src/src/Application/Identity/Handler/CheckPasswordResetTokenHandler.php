<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\GuardsAgainstStaleResetRequests;
use App\Application\Identity\Query\CheckPasswordResetToken;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\ResetToken;
use App\Domain\Shared\Port\Clock;

/**
 * Judges a reset link without touching it: may this token still set a password on this account?
 *
 * **IT MUTATES NOTHING AND DISPATCHES NOTHING** (AC-12). No `redeem()`, no `save()`, no
 * `DomainEventDispatcher` in the constructor at all — the absence of that collaborator is the
 * clearest statement this file can make. That is not an incidental property of a read-only query; it
 * is the entire reason the method exists, and it is worth stating why rather than leaving a future
 * reader to assume the redemption was forgotten.
 *
 * INVERSION #3 vs `identity-email-verification`, AND THE REASON IS MAIL-SCANNER PREFETCH.
 * `VerifyEmailWithTokenHandler` — sitting in this same directory — **redeems on the GET**, and is
 * right to. This handler is what the GET runs here, and it deliberately does the opposite, so a
 * reader who notices the difference must find the reason here rather than conclude one of them is a
 * bug.
 *
 * Security appliances and mail clients fetch every URL in an incoming message before a human ever
 * sees it. Slice 2 can afford to have that prefetch burn its challenge because *its* replay is
 * harmless: verifying an already-verified address is a no-op, so the scanner's fetch and the human's
 * click compose to the same end state, and the human is told "verified" either way. Here redemption
 * is **destructive and repeatable**, so a replay must be refused (`PasswordResetRequest::redeem()`)
 * — which means that if the GET burnt the challenge, the scanner would consume the link and the
 * actual user would open a dead one, **every time**, on the flow people reach when they are already
 * locked out. So the GET only judges, and the POST that sets the password is what burns the token.
 * Two entry points, one rule, one implementation: both call
 * `PasswordResetRequest::assertRedeemableWith()`, so the day they could drift never arrives.
 *
 * WHY IT RETURNS `void` AND THROWS, WHICH LOOKS ODD FOR A QUERY. The answer this query gives is
 * "yes, and there is nothing else you need" — there is no value to hand back, because the caller
 * already holds the token and the aggregate stays where it is. A `bool` would be the obvious
 * alternative and would be wrong for a specific reason: it would collapse **four distinguishable
 * causes** — superseded, spent, expired, forged, plus the stale-request case and the dangling-user
 * case — into one, **in the layer that has to keep them distinct**. The log needs to know which one
 * fired (a `StalePasswordResetRequest` in particular is the only signal that the reissue sweep
 * failed), and so does every test that claims to pin these branches. That the *visitor* sees one
 * identical neutral response for all of them (AC-16) is a **presentation** policy chosen to defeat
 * enumeration, and presentation policy is never a reason to throw information away three layers
 * down, where the log and the tests live.
 *
 * **NO OUTCOME ENUM, UNLIKE SLICE 2'S `VerificationOutcome`.** That enum exists because verification
 * has two *successful* answers — verified just now, or already verified — and its controller has to
 * pick a different flash between them without issuing a second query. Here there is exactly one
 * success: the link is good, show the form. An enum with one case would be ceremony that documents
 * nothing, and the day a second success appears is the day to add one.
 */
final readonly class CheckPasswordResetTokenHandler
{
    use GuardsAgainstStaleResetRequests;

    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private ResetTokenGenerator $tokens,
        private Clock $clock,
    ) {
    }

    /**
     * NO TRANSACTION BOUNDARY, because nothing is written. This is the only handler in the context
     * of which that is true, and it is a guarantee the GET route depends on.
     *
     * Idempotent and side-effect-free by construction: run it a thousand times, from a scanner, a
     * link preview, a refresh or a browser prefetch, and the system is in exactly the state it was
     * in. That is what makes prefetch safe rather than merely tolerated.
     *
     * @throws \App\Domain\Identity\Exception\InvalidResetToken            when the token is malformed
     * @throws PasswordResetRequestNotFound                                when no request holds that digest
     * @throws UserNotFound                                                when the request points at a user that no longer exists
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkInvalidated when a newer request superseded this one
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed when the challenge was already burnt
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkExpired     when the link is past its deadline
     * @throws \App\Domain\Identity\Exception\PasswordResetTokenMismatch   when the presented digest is not the request's
     * @throws \App\Domain\Identity\Exception\StalePasswordResetRequest    when the request predates the user's last password change
     */
    public function __invoke(CheckPasswordResetToken $query): void
    {
        // THE VALUE OBJECT IS THE GATE, AND IT CLOSES **BEFORE ANY DATABASE QUERY** (AC-17). 43
        // URL-safe characters or nothing reaches the repository — so a malformed token costs one
        // regex rather than an index lookup, and, more to the point, a malformed token and an
        // unissued one cannot be told apart by how long the response took. Validating after the
        // lookup would have been the same code in the wrong order and a timing oracle for free.
        $plain = ResetToken::fromString($query->token);
        $hash = $this->tokens->hash($plain);

        // The lookup is by digest, so no plaintext is stored to be stolen and the exception below
        // can carry no token — `forToken()` takes no argument on purpose (AC-10).
        //
        // The repository returns the request whether or not it is redeemed, invalidated or expired.
        // A repository that hid dead rows would make "expired an hour ago" and "never existed" the
        // same `null`, destroying the information the log and the tests need in order to satisfy a
        // presentation goal that lives three layers out.
        $request = $this->requests->findByTokenHash($hash) ?? throw PasswordResetRequestNotFound::forToken();

        // A reference across an aggregate boundary is a `UserId`, so re-loading the User through its
        // own repository is not a detour — it is the boundary working. There is no `$request->user()`
        // to reach through, by design (AC-40). The user is needed here even though nothing is
        // written, because the staleness guard below is a comparison between the two aggregates.
        $user = $this->users->findById($request->userId()) ?? throw UserNotFound::withId($request->userId());

        // The full judgement, unmutating: superseded, spent, expired, mismatched — in that order,
        // with the only check that touches a secret performed last. The POST calls the same method.
        $request->assertRedeemableWith($hash, $this->clock->now());

        // I-23, the second layer, shared verbatim with `ResetPasswordWithTokenHandler` via the
        // trait — because a rule the GET and the POST disagree about is worse than either answer.
        $this->assertNotStale($request, $user);
    }
}
