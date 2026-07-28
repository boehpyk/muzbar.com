<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\RequestEmailVerification;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Exception\EmailAlreadyVerified;
use App\Domain\Identity\Exception\TooManyVerificationRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\Port\VerificationMailer;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Issues one verification challenge and mails it.
 *
 * A handler is deliberately boring, and this one is the longest in the codebase only because it
 * orchestrates six ports rather than because it thinks. Read down `__invoke()` and every decision is
 * somewhere else: what an address is, in `Email`; how long a link lives and how many may be issued,
 * on `EmailVerificationRequest`; what a token is made of and how it is digested, behind
 * `VerificationTokenGenerator`; what a link looks like and how mail leaves the building, behind
 * `VerificationMailer`. What is left here is *sequence* — and in this use case the sequence is the
 * interesting part, which is why two orderings below carry comments rather than being left to
 * inference.
 *
 * THIS HANDLER IS THE ONLY PLACE IN THE SYSTEM THAT HOLDS A PLAINTEXT TOKEN. It receives one from
 * the generator, hands it to the mailer, and lets it go out of scope. It never reaches the
 * aggregate (which takes a `HashedVerificationToken` by signature), never reaches the event (which
 * carries ids and instants only, AC-26), and never reaches the database. That containment is the
 * reason the mail is sent by a direct port call rather than by a listener on
 * `EmailVerificationRequested` — routing the secret through an event bus to reach a listener would
 * be the more elegant-looking wiring and would put a live credential in every subscriber, every
 * debug dump and, once the transport is asynchronous, every queue row.
 *
 * ALL THREE DECLARED THROWS ARE NORMAL OUTCOMES, NOT ERRORS. An unknown address, an already-verified
 * account and a sixth request inside the hour are ordinary things that happen to a public form; none
 * of them is a bug. They are exceptions rather than cases of a result enum because in each of them
 * the **domain** genuinely cannot proceed — there is nothing to issue — and an enum case would let a
 * caller ignore the answer and continue. What they are *not* is a decision about what the visitor
 * should see. Collapsing the three into one indistinguishable response is a **presentation** policy,
 * it is chosen to defeat account enumeration, and it belongs at the presentation boundary.
 *
 * That separation is precisely what lets one handler serve two very different adapters: the
 * registration listener catches these and logs a real anomaly (an account that just registered
 * should not be unfindable or already verified), while the anonymous resend form catches the same
 * three and renders the identical neutral response it renders for success (AC-15). A handler that
 * had made the choice itself could only have served one of them.
 *
 * Every collaborator is a port, so the whole use case is testable with a frozen clock, a fake
 * generator whose token the test knows, a recording mailer and a spy dispatcher — no SMTP, no
 * randomness, and an assertable `issuedAt`.
 */
final readonly class RequestEmailVerificationHandler
{
    public function __construct(
        private UserRepository $users,
        private EmailVerificationRequestRepository $requests,
        private VerificationTokenGenerator $tokens,
        private VerificationMailer $mailer,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: one command, one logical transaction, owned by `save()` inside the
     * adapter. Nothing here names Doctrine and nothing here can leave half a request behind.
     *
     * **This use case is deliberately not idempotent** (the contrast with `VerifyEmailWithToken` is
     * worth noticing). Every call mints a new token and a new row, because that is exactly what
     * "resend" means to a user staring at an empty inbox. Reissuing does *not* invalidate the
     * earlier links, and it does not need to: once any token verifies the account, every other live
     * token is inert, since redeeming one hits the already-verified short-circuit. The thing that
     * stops a non-idempotent public endpoint being a mail cannon is the rate limit at step 4, backed
     * by the per-IP limiter at the HTTP boundary.
     *
     * @throws \App\Domain\Identity\Exception\InvalidEmail when the address is not well-formed
     * @throws UserNotFound                                when no account holds that address
     * @throws EmailAlreadyVerified                        when the account needs no challenge
     * @throws TooManyVerificationRequests                 when rule I-12 refuses a sixth issuance
     */
    public function __invoke(RequestEmailVerification $command): void
    {
        $email = Email::fromString($command->email);
        $user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);

        if ($user->isEmailVerified()) {
            throw EmailAlreadyVerified::forUser($user->id());
        }

        // ONE `now()` FOR THE WHOLE USE CASE, NOT ONE PER STEP. The rate-limit window below and the
        // `issuedAt` at step 6 are two halves of a single statement — "at the instant T, fewer than
        // five requests existed in the preceding hour, so here is one issued at T" — and that
        // statement only composes if both halves name the same T. Two `now()` calls would usually
        // agree (the Clock contract mandates whole seconds, so they differ only when the call
        // straddles a second boundary) and that "usually" is the whole problem: a frozen clock in a
        // test returns one value however many times it is asked, so the discrepancy is invisible to
        // every test that could catch it and appears only in production, rarely, as an off-by-one
        // second in a window nobody is watching. A value that a test cannot distinguish from correct
        // is a value that must be made correct by construction.
        $now = $this->clock->now();

        // `modify()` on a UTC instant with a fixed literal is unambiguous — no locale, no DST, no
        // month-length arithmetic — which is why the natural-language grammar that
        // `EmailVerificationRequest::issue()` refuses for deriving `expiresAt` is fine for deriving a
        // throwaway query bound here. `$since` is never stored and no invariant depends on it.
        $since = $now->modify('-1 hour');

        if ($this->requests->countIssuedForUserSince($user->id(), $since) >= EmailVerificationRequest::MAX_ISSUES_PER_HOUR) {
            throw TooManyVerificationRequests::forUser($user->id());
        }

        // The plaintext exists from here to the `sendVerificationLink()` call and nowhere else.
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        $request = EmailVerificationRequest::issue(
            $this->requests->nextIdentity(),
            $user->id(),
            $hash,
            $now,
        );

        // SAVE BEFORE SEND. A link sitting in somebody's inbox must always have a row behind it. The
        // reverse order can produce a link that verifies nothing: send succeeds, the save then
        // fails, and the user receives a perfectly valid-looking URL whose token matches no request
        // — indistinguishable, when they click it, from a forgery. Failing the other way round is
        // strictly better: an unmailed row is invisible, harmless, and expires on its own.
        $this->requests->save($request);
        $this->mailer->sendVerificationLink($user->email(), $token, $request->expiresAt());

        // SEND BEFORE DISPATCH. `EmailVerificationRequested` is an audit fact — "we asked this
        // account to prove its address at this time" — and it is only true once the whole use case
        // succeeded. Dispatching before the mailer accepted the message would publish a claim the
        // system might then fail to make good on, and ADR-0008's rule (publish only what has been
        // committed) is the same rule one step further along.
        $this->events->dispatch(...$request->releaseEvents());
    }
}
