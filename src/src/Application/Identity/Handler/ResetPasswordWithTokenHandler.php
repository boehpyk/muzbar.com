<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\ResetPasswordWithToken;
use App\Application\Identity\GuardsAgainstStaleResetRequests;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\PlainPassword;
use App\Domain\Identity\ValueObject\ResetToken;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Redeems a reset link: burns the challenge and sets a new password on the account behind it.
 *
 * The destructive half of the pair whose read-only half is `CheckPasswordResetTokenHandler`. The GET
 * judged; this is what acts, and it is the only place in the system where a person who cannot prove
 * they know the old password can change it.
 *
 * **IT IS THE DIRECT COUNTERPART OF `VerifyEmailWithTokenHandler`, AND IT INVERTS IT IN THREE
 * PLACES.** Those two files sit next to each other in this directory forever, so each inversion
 * carries a comment saying *why* it is inverted rather than merely what it does: a replay is refused
 * rather than absorbed (the aggregate's `redeem()`), nothing was burnt on the GET
 * (`CheckPasswordResetTokenHandler`), and — the one that lives in this file — **the two saves happen
 * in the opposite order**, for a reason argued at the call site below.
 *
 * Note the collaborator slice 2's redemption handler does not have: a `PasswordHasher`. Verification
 * is the end of a conversation, so it takes no input from the person clicking; a reset takes the most
 * sensitive input the system accepts, which is why *where* that input is turned into a value object
 * is itself a security decision — see the comment above `PlainPassword::fromString()` below.
 *
 * Every failure below is an exception, and the *visitor* is told the same thing for all of them —
 * malformed, unknown, superseded, spent, expired, mismatched, stale, and the dangling-user case are
 * one indistinguishable response with one flash string (AC-16), because distinguishing them hands a
 * stranger an oracle. They stay eight distinct exceptions because the **system** must be able to tell
 * them apart in a log and in a test; `StalePasswordResetRequest` in particular is the only signal
 * that a reissue sweep was lost, and a log that could not separate it from an expired link would
 * waste it. Throwing information away at the domain level to achieve a presentation goal is solving
 * the problem in the wrong layer.
 */
final readonly class ResetPasswordWithTokenHandler
{
    use GuardsAgainstStaleResetRequests;

    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private ResetTokenGenerator $tokens,
        private PasswordHasher $hasher,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: the same shape as every other handler here — the adapter owns
     * persist-and-flush, events publish after a successful save — with one twist that is the whole
     * lesson of the slice. **Two aggregates change, so there are two saves, and their order is
     * load-bearing.** See the comment at the call site, and ADR-0011 decision 5.
     *
     * **Deliberately not idempotent.** A replay is refused, not absorbed (`redeem()` throws
     * `PasswordResetLinkAlreadyUsed`), which is the sharpest single contrast with
     * `VerifyEmailWithTokenHandler` and the reason the two aggregates cannot share a base class.
     *
     * @throws \App\Domain\Identity\Exception\InvalidResetToken            when the token is malformed
     * @throws PasswordResetRequestNotFound                                when no request holds that digest
     * @throws UserNotFound                                                when the request points at a user that no longer exists
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkInvalidated when a newer request superseded this one
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed when the challenge was already burnt
     * @throws \App\Domain\Identity\Exception\PasswordResetLinkExpired     when the link is past its deadline
     * @throws \App\Domain\Identity\Exception\PasswordResetTokenMismatch   when the presented digest is not the request's
     * @throws \App\Domain\Identity\Exception\StalePasswordResetRequest    when the request predates the user's last password change
     * @throws \App\Domain\Identity\Exception\WeakPassword                 when the new password does not meet the policy
     */
    public function __invoke(ResetPasswordWithToken $command): void
    {
        // STEPS 1–4 RE-RUN THE GET'S WORK RATHER THAN TRUSTING IT, AND THAT IS NOT DUPLICATION.
        // `CheckPasswordResetTokenHandler` ran in a **different HTTP request** — possibly minutes
        // ago, possibly on behalf of a mail scanner rather than a person, and in any case over a
        // token that has since been sitting in a session while the user typed. Everything it
        // established could have stopped being true in between: a newer request may have superseded
        // this one, the hour may have elapsed, the account may have been deleted, another reset may
        // have completed. A check whose result is carried forward across a request boundary is not a
        // check, it is a memory of one — and on the flow that hands out account access, the only
        // acceptable time to have verified something is now.
        $plain = ResetToken::fromString($command->token);
        $hash = $this->tokens->hash($plain);

        $request = $this->requests->findByTokenHash($hash) ?? throw PasswordResetRequestNotFound::forToken();

        // The boundary working, again: a `UserId`, re-loaded through its own repository, with no
        // `$request->user()` to reach through (AC-40).
        $user = $this->users->findById($request->userId()) ?? throw UserNotFound::withId($request->userId());

        // One instant for the whole mutation: `redeemedAt`, `passwordChangedAt` and possibly
        // `emailVerifiedAt` describe a single occurrence and would be indefensible to record a
        // second apart. It is also the instant the staleness comparison below reads against, so the
        // judgement and the writes it licenses name the same moment.
        $now = $this->clock->now();

        // The same four-part judgement the GET applied, from the same method on the aggregate, so
        // the two entry points cannot drift.
        $request->assertRedeemableWith($hash, $now);

        // I-23, the second layer (AC-26). Shared with the GET handler through the trait so the
        // comparison and its strict `<` are written exactly once.
        $this->assertNotStale($request, $user);

        // THE PASSWORD IS VALIDATED AND HASHED **AFTER** THE TOKEN CHECKS, ON PURPOSE.
        //
        // Reading top to bottom it looks natural to parse the form's input first and only then go to
        // the database, and that would be a CPU-amplification hole: password hashing is *designed*
        // to be expensive, so an attacker POSTing garbage tokens with 4 kB passwords would spend one
        // cheap request each to make this server spend a deliberately slow Argon2/bcrypt run. With
        // the checks in this order, **an attacker holding no valid token never reaches the hasher at
        // all** — they get a digest, an index lookup and an exception. That is what makes the per-IP
        // limiter on this route belt-and-braces rather than the only thing standing between a
        // stranger and the CPU budget.
        //
        // `PlainPassword` enforces the same policy as registration, deliberately reusing slice 1's
        // value object rather than inventing a second one (AC-22): two places that define "strong
        // enough" are two places that drift, and the one that drifts loose is the one people use
        // when they are locked out and in a hurry.
        $plainPassword = PlainPassword::fromString($command->newPassword);
        $newHash = $this->hasher->hash($plainPassword);

        $request->redeem($hash, $now);
        $user->changePassword($newHash, $now);

        // A SUCCESSFUL RESET ALSO VERIFIES THE EMAIL (AC-28, ADR-0011 decision 6). One line, and it
        // needs the reason rather than the fact: **the proof came from the channel, not from the
        // password change.** This person clicked a link that was delivered to that mailbox and
        // nowhere else, which is exactly the same proof the verification flow asks for — and
        // demanding they re-prove it by clicking a second link mailed to the same address would be
        // incoherent. Refusing to verify here would also strand every unverified account with no
        // recovery path at all: it cannot log in (`VerifiedAccountUserChecker` enforces
        // `User::isUsable()`), so if the reset flow declined to verify, it could never become usable.
        //
        // IT IS **HERE** RATHER THAN INSIDE `changePassword()`, AND THAT PLACEMENT IS THE SECURITY
        // DECISION. The coupling belongs to *this use case*, which knows which channel proved what.
        // A future authenticated "change my password" screen proves nothing about the mailbox — the
        // person is already logged in, no mail was sent, no link was clicked — so an aggregate method
        // that verified an address would silently turn that screen into a verification bypass.
        // `User::changePassword()`'s docblock already commits to this call site existing; moving this
        // line into the aggregate would break a promise made in writing there.
        //
        // `verifyEmail()` is idempotent and records nothing on a repeat (I-24), which is what makes
        // the already-verified case free rather than a branch (AC-30).
        $user->verifyEmail($now);

        // REQUEST FIRST, USER SECOND — **THE INVERSION OF `VerifyEmailWithTokenHandler`, WHICH SAVES
        // USER FIRST AND REQUEST SECOND.** Both files carry a comment claiming their order is the
        // safe one, and both are right; a reader who notices the contradiction and finds no reason
        // will eventually "align" them, and aligning them the wrong way here is an account-takeover
        // primitive. So: ADR-0009 decision 4 states the rule — *pick the order whose crash window is
        // benign, and write down why at the call site* — and the same rule applied to a different
        // effect produces the opposite answer, which is the strongest available evidence that it is a
        // real rule rather than a habit.
        //
        // Consider the crash window between the two lines below:
        //
        // - **User first, request second** (slice 2's order) → a crash leaves a **changed password
        //   and a live token**, and that token can be presented again to set *another* password —
        //   possibly by whoever stole the link, on an account whose owner believes they have just
        //   recovered it. This is precisely the danger ADR-0009 decision 5 named. **Rejected.**
        // - **Request first, user second** → a crash leaves a **burnt token and an unchanged
        //   password**. The user is confused, requests another link, and gets one. Annoying;
        //   entirely safe. **Adopted.**
        //
        // Slice 2's window is the mirror image: losing its second save leaves a verified user and a
        // still-live verification token, which is inert because redeeming it hits the
        // already-verified short-circuit. Its effect is idempotent, so a surviving token costs
        // nothing; ours is destructive and repeatable, so a surviving token costs the account. Same
        // rule, different effect, opposite order.
        //
        // Because a safe ordering exists, the rule spanning the two aggregates does not need a single
        // transaction, which is exactly what licenses them to be two aggregates at all. (With today's
        // Doctrine adapter both roots are managed by one EntityManager, so the first `save()`'s flush
        // commits both changes anyway. That is a happy accident, not the guarantee; this ordering is
        // what keeps the design correct the day it stops being true — a second database, an outbox, a
        // repository that batches, or simply a `save()` that stops flushing.)
        $this->requests->save($request);
        $this->users->save($user);

        // `$user` releases `UserPasswordChanged` and, for an account that was not yet verified, also
        // `UserEmailVerified` — the same event slice 1 defined, now dispatched by a second use case,
        // which is a clean demonstration that events are facts rather than commands: the same fact
        // from a different cause needs no new type. `$request` releases nothing, because `redeem()`
        // records none — the fact worth publishing is "this account's password changed", and it
        // belongs to the aggregate that owns the password. The spread is written anyway so this call
        // site does not have to know that and stays correct if `redeem()` ever starts recording
        // something. The variadic port accepts an empty list, so no guard is needed.
        $this->events->dispatch(...$user->releaseEvents(), ...$request->releaseEvents());
    }
}
