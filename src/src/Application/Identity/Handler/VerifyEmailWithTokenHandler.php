<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\VerifyEmailWithToken;
use App\Application\Identity\VerificationOutcome;
use App\Domain\Identity\Exception\EmailVerificationRequestNotFound;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\VerificationToken;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Redeems a verification link: burns the challenge and verifies the account behind it.
 *
 * The second adapter over the verification idea, and the reason the slice adds a use case instead of
 * changing one. `VerifyUserEmailHandler` stays exactly as it is (AC-29): it is keyed by *email*,
 * trusts its caller, and exists so an operator can run `muzbar:identity:verify-email` — an
 * administrative act. This handler is keyed by a *token*, trusts nobody, and is reachable by any
 * anonymous visitor with a URL. The same aggregate method (`User::verifyEmail()`) sits underneath
 * both. Two use cases that end in the same mutation are still two use cases when their preconditions
 * differ this much; merging them would mean the console command inherited a token check it has no
 * token for, or the public route inherited the console's trust.
 *
 * Note the absent collaborator: there is **no mailer here**. Verification is the end of the
 * conversation, not a step in it.
 *
 * Every failure below is an exception, but the *visitor* is told the same thing for all of them —
 * malformed, unknown, expired, already redeemed, mismatched, and the dangling-user case are one
 * indistinguishable response with one flash string (AC-10 … AC-12), because distinguishing them
 * hands a stranger an oracle. They stay six distinct exceptions because the **system** must be able
 * to tell them apart in a log and in a test. Throwing information away at the domain level to
 * achieve a presentation goal is solving the problem in the wrong layer.
 */
final readonly class VerifyEmailWithTokenHandler
{
    public function __construct(
        private UserRepository $users,
        private EmailVerificationRequestRepository $requests,
        private VerificationTokenGenerator $tokens,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: the same shape as every other handler here — the adapter owns
     * persist-and-flush, events publish after a successful save — with one twist that is the whole
     * lesson of the slice. **Two aggregates change, so there are two saves, and their order is
     * load-bearing.** See the comment at the call site, and ADR-0009 decision 3.
     *
     * @throws \App\Domain\Identity\Exception\InvalidVerificationToken             when the token is malformed
     * @throws EmailVerificationRequestNotFound                                    when no request holds that digest
     * @throws \App\Domain\Identity\Exception\EmailVerificationLinkExpired         when the link is past its deadline
     * @throws \App\Domain\Identity\Exception\EmailVerificationLinkAlreadyRedeemed when the challenge was already burnt
     * @throws \App\Domain\Identity\Exception\EmailVerificationTokenMismatch       when the presented digest is not the request's
     * @throws UserNotFound                                                        when the request points at a user that no longer exists
     */
    public function __invoke(VerifyEmailWithToken $command): VerificationOutcome
    {
        // The value object is the gate: 43 URL-safe characters or nothing reaches the repository.
        $plain = VerificationToken::fromString($command->token);
        $hash = $this->tokens->hash($plain);

        // The lookup is by digest, so the stored plaintext does not exist to be stolen and the
        // exception below can carry no token — `forToken()` takes no argument on purpose (AC-2).
        $request = $this->requests->findByTokenHash($hash) ?? throw EmailVerificationRequestNotFound::forToken();

        // A reference across an aggregate boundary is a `UserId`, so re-loading the User through its
        // own repository is not a detour — it is the boundary working. There is no `$request->user()`
        // to reach through, by design (AC-34).
        $user = $this->users->findById($request->userId()) ?? throw UserNotFound::withId($request->userId());

        // THE REPLAY SHORT-CIRCUIT, AND IT COMES BEFORE `redeem()` (AC-8).
        //
        // In the real world the first GET on a verification link is frequently a robot: mail clients
        // pre-fetch links to render previews, and corporate security scanners follow every URL in an
        // incoming message before the human ever sees it. By the time the person actually clicks,
        // the token has often already been redeemed — so the *human's* click is the replay. A flow
        // that treats a replay as an error therefore punishes the one participant who did nothing
        // wrong, and shows them "this link has already been used" for a link they are using for the
        // first time. That reads as broken software and generates a support ticket.
        //
        // Putting the check here rather than after `redeem()` is what makes the difference: at this
        // point the account is already verified, so there is nothing left to do and saying so is the
        // truth. This branch **mutates nothing and dispatches nothing** — no second
        // `UserEmailVerified`, no write, no flush — which is what makes the whole use case safely
        // idempotent under arbitrary repetition.
        if ($user->isEmailVerified()) {
            return VerificationOutcome::AlreadyVerified;
        }

        // One instant for both mutations: `redeemedAt` and `emailVerifiedAt` describe a single
        // occurrence and would be indefensible to record a second apart.
        $now = $this->clock->now();

        $request->redeem($hash, $now);
        $user->verifyEmail($now);

        // USER FIRST, REQUEST SECOND — the ordering argued in ADR-0009 decision 3 and in
        // `EmailVerificationRequest`'s docblock. Consider the crash window between the two lines.
        // Losing the second save leaves a **verified user and a still-live token**: redeeming that
        // token later hits the short-circuit above and is a no-op, so nothing is wrong and nobody is
        // inconvenienced. The reverse order would leave a **burnt token and an unverified user** —
        // somebody locked out of their own link with no way back but the resend form.
        //
        // Because a safe ordering exists, the rule spanning the two aggregates does not need a
        // single transaction, which is exactly what licenses them to be two aggregates at all.
        // (With today's Doctrine adapter both are managed by one EntityManager, so the first
        // `save()`'s flush commits both changes anyway. That is a happy accident, not the
        // guarantee; this ordering is what keeps the design correct the day it stops being true.)
        $this->users->save($user);
        $this->requests->save($request);

        // `$user` releases `UserEmailVerified`; `$request` releases nothing, because `redeem()`
        // records no event — the fact worth publishing is "this address is verified", and it belongs
        // to the aggregate that owns the address. The spread is here anyway so this call site does
        // not have to know that, and stays correct if `redeem()` ever starts recording something.
        // The variadic port accepts an empty list, so no guard is needed.
        $this->events->dispatch(...$user->releaseEvents(), ...$request->releaseEvents());

        return VerificationOutcome::Verified;
    }
}
