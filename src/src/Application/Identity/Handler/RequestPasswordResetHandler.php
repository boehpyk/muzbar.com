<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\RequestPasswordReset;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Exception\TooManyPasswordResetRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\PasswordResetMailer;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Issues one password-reset challenge, kills every older one, and mails the new link.
 *
 * The counterpart of `RequestEmailVerificationHandler`, and a handler is deliberately boring: read
 * down `__invoke()` and every decision is somewhere else. What an address is, in `Email`; how long a
 * link lives and how many may be issued, on `PasswordResetRequest`; what a token is made of and how
 * it is digested, behind `ResetTokenGenerator`; what a link looks like and how mail leaves the
 * building, behind `PasswordResetMailer`. What is left here is **sequence** — and on this use case
 * the sequence is the security, which is why three orderings below carry comments rather than being
 * left to inference.
 *
 * THIS HANDLER IS THE ONLY PLACE IN THE SYSTEM THAT HOLDS A PLAINTEXT RESET TOKEN. It receives one
 * from the generator, hands it to the mailer, and lets it go out of scope. It never reaches the
 * aggregate (which takes a `HashedResetToken` by signature), never reaches the event (which carries
 * ids and instants only, AC-32), and never reaches the database.
 *
 * **NO LISTENER SENDS THIS MAIL, AND THAT ASYMMETRY IS DELIBERATE.** `PasswordResetRequested` is
 * dispatched at the end of this method, and hanging the mail off it would be the more
 * elegant-looking wiring — a domain event triggers a side effect, textbook. It is rejected because
 * the mail needs the **plaintext** token, and the event carries none and must not: a secret in an
 * event is a secret in every subscriber, every debug dump of that event, and every
 * `messenger_messages` row for as long as the retry policy keeps it. Here that secret is a live
 * account-takeover credential, so the blast radius is the account. Elegance that widens the blast
 * radius of a secret is not elegance.
 *
 * Read that next to `IssueVerificationOnUserRegistered`, which *is* a listener, because the two are
 * not in tension and a reader who sees only one of them will conclude that this codebase cannot make
 * up its mind. That listener subscribes to `UserRegistered` in order to decide **whether to start a
 * use case at all** — "a user registered" is a fact, "so send them a link" is a policy, and keeping
 * them apart is what stops every future registration path inheriting the mail. It carries no secret:
 * it reads an id and an address off the event and dispatches a command. The rule that reconciles the
 * two is therefore not "listeners good" or "listeners bad" but: **an event may trigger a use case; an
 * event may not carry the use case's secret.** This flow has no trigger event anyway — it starts at
 * a form — so only the second half is doing work here.
 *
 * ALL THREE DECLARED THROWS ARE NORMAL OUTCOMES, NOT ERRORS. An unknown address, a fourth request
 * inside the hour, and an address that slipped through the form validator into the gap `Email`
 * refuses are ordinary things that happen to a public form; none of them is a bug. They are
 * exceptions rather than cases of a result enum because in each of them the **domain** genuinely
 * cannot proceed — there is no challenge to issue and no mail to send — and an enum case would let a
 * caller ignore the answer and carry on. What they are *not* is a decision about what the visitor
 * should see. Collapsing all three plus success into one indistinguishable response is a
 * **presentation** policy, chosen to defeat account enumeration, and it belongs at the presentation
 * boundary (AC-5, AC-6).
 *
 * That separation is exactly what would let a **second adapter** — a console tool, an admin action,
 * a support workflow — treat the same event as a real anomaly worth logging loudly, where the public
 * form must reveal nothing. A handler that made the choice itself could only ever serve one of them.
 *
 * Every collaborator is a port, so the whole use case is testable with a frozen clock, a fake
 * generator whose token the test knows, a recording mailer and a spy dispatcher — no SMTP, no
 * randomness, and an assertable `issuedAt`.
 */
final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private ResetTokenGenerator $tokens,
        private PasswordResetMailer $mailer,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * TRANSACTION BOUNDARY: one command, one logical transaction, owned by `save()` inside the
     * adapter. Nothing here names Doctrine. The sweep at step 5 issues N ≤ 3 saves before the new
     * request's own; with today's adapter each is a flush, so a crash mid-sweep leaves some older
     * requests invalidated and some not — which is harmless, because `invalidate()` is idempotent
     * and the staleness guard (I-23) catches any survivor that outlives a completed reset.
     *
     * **THIS USE CASE IS DELIBERATELY NOT IDEMPOTENT, AND UNLIKE SLICE 2'S RESEND THAT HAS TEETH.**
     * `RequestEmailVerification` mints a new token and leaves the old ones alive, because a
     * verification token goes inert the moment any one of them verifies the account, so a repeat
     * costs a row and an email. Here **every call kills the previous link** (step 5), so a repeat is
     * destructive: a user who requests twice and clicks the mail that arrived first is told the link
     * is dead. That is an accepted UX wrinkle rather than an oversight — it is the price of not
     * leaving a stolen link useful for its whole hour — and it is *why* the per-user cap is 3 rather
     * than verification's 5. `PasswordResetRequest::MAX_ISSUES_PER_HOUR` plus the per-IP limiter at
     * the HTTP boundary are what stop a non-idempotent public endpoint being a weapon: one stops a
     * single mailbox being flooded, the other stops one machine hammering the form, and either alone
     * leaves an obvious hole.
     *
     * @throws \App\Domain\Identity\Exception\InvalidEmail when the address is not well-formed
     * @throws UserNotFound                                when no account holds that address
     * @throws TooManyPasswordResetRequests                when rule I-21 refuses a fourth issuance
     */
    public function __invoke(RequestPasswordReset $command): void
    {
        $email = Email::fromString($command->email);
        $user = $this->users->findByEmail($email) ?? throw UserNotFound::withEmail($email);

        // ONE `now()` FOR THE WHOLE USE CASE, NOT ONE PER STEP. The rate-limit window below and the
        // `issuedAt` at step 7 are two halves of a single statement — "at the instant T, fewer than
        // three requests existed in the preceding hour, so here is one issued at T" — and that
        // statement only composes if both halves name the same T. Two `now()` calls would usually
        // agree (the Clock contract mandates whole seconds, so they differ only when the pair
        // straddles a second boundary) and that "usually" is the whole problem: a frozen clock in a
        // test returns one value however many times it is asked, so the discrepancy is invisible to
        // every test that could catch it and shows up only in production, rarely, as an off-by-one
        // second in a window nobody is watching. A value a test cannot distinguish from correct is a
        // value that must be made correct by construction.
        //
        // It is also the instant the invalidation sweep stamps on the superseded requests, so the
        // whole reissue — old ones closed, new one opened — reads as one occurrence rather than as a
        // sequence of things that happened to each other.
        $now = $this->clock->now();

        // THE CAP IS CHECKED **BEFORE** THE INVALIDATION SWEEP, AND THE ORDERING IS A FAILURE
        // CONTRACT RATHER THAN A STYLE PREFERENCE (AC-7).
        //
        // Run the sweep first and the anti-abuse rule becomes a denial-of-recovery tool: an attacker
        // who knows a victim's address — which is the one thing an attacker on this flow reliably
        // has — could POST the form three times an hour, kill the victim's in-flight link each time,
        // and never receive a single mail, because the mails go to the victim's mailbox. The victim
        // would then click a legitimate link, be told it is dead, request another, and race an
        // attacker who can always be faster than a human reading email. Refusing *before* touching
        // anything means a refused request changes nothing at all: the victim's live link survives
        // the fourth submission exactly as it survived the third.
        //
        // Note that this rule spans instances, so no aggregate can hold it (I-21). The bound lives
        // on `PasswordResetRequest`, where the policy belongs; the count lives in the repository,
        // where counting is possible; the comparison lives here, where both are in scope. It can
        // lose a race under concurrent submissions — the worst case is a fourth email.
        //
        // `modify()` on a UTC instant with a fixed literal is unambiguous — no locale, no DST, no
        // month-length arithmetic — which is why the natural-language grammar that
        // `PasswordResetRequest::issue()` refuses for deriving `expiresAt` is fine for deriving a
        // throwaway query bound here. `$since` is never stored and no invariant depends on it.
        $since = $now->modify('-1 hour');

        if ($this->requests->countIssuedForUserSince($user->id(), $since) >= PasswordResetRequest::MAX_ISSUES_PER_HOUR) {
            throw TooManyPasswordResetRequests::forUser($user->id());
        }

        // INVERSION #2 vs `identity-email-verification`: **issuing a new challenge kills the
        // outstanding ones here, and does not there** (AC-9, ADR-0011 decision 4). Slice 2's
        // `RequestEmailVerificationHandler` has no loop like this and is right not to, so a reader
        // diffing the two files must find the reason here rather than assume one of them is a bug.
        //
        // Slice 2 could decline invalidation because of a **specific property**: once any
        // verification token verifies the account, every other live token is inert, since redeeming
        // one hits the already-verified short-circuit. Reset has no such property. **Each live reset
        // token is an independent, repeatable chance to set a password**, so leaving the old ones
        // alive would mean a single stolen link stays useful for its whole hour no matter how many
        // times the legitimate owner reissues — and reissuing is precisely what a person does when
        // they suspect something is wrong. This is ADR-0009 decision 5's own carve-out being cashed
        // in, not a new argument: it named the property rather than the conclusion, which is what
        // made it checkable here. It no longer holds.
        //
        // **THROUGH THE AGGREGATE, ONE AT A TIME — NEVER A BULK DQL `UPDATE`.** A bulk update would
        // set `invalidated_at` without the aggregate ever agreeing, which makes I-17 (never both
        // redeemed and invalidated) unenforceable and parks a security rule in SQL where no unit
        // test can reach it. The volume is what makes the principled choice also the cheap one: the
        // set is bounded by `MAX_ISSUES_PER_HOUR`, so N ≤ 3. If it ever were not, the port would
        // grow a method whose docblock said why — it would not grow one silently because a loop
        // looked slow.
        //
        // `invalidate()` refuses a redeemed request and returns early on an already-invalidated one,
        // so a re-run of this sweep after a crash or a retry is harmless.
        foreach ($this->requests->findOutstandingForUser($user->id()) as $old) {
            $old->invalidate($now);
            $this->requests->save($old);
        }

        // The plaintext exists from here to the `sendResetLink()` call and nowhere else in the
        // system — not in the aggregate, not in the event, not in the database, not in a log.
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        $request = PasswordResetRequest::issue(
            $this->requests->nextIdentity(),
            $user->id(),
            $hash,
            $now,
        );

        // SAVE BEFORE SEND. A link sitting in somebody's inbox must always have a row behind it. The
        // reverse order can produce a link that resets nothing: the send succeeds, the save then
        // fails, and the user receives a perfectly valid-looking URL whose token matches no request
        // — indistinguishable, when they click it, from a forgery, on the one flow where people are
        // already primed to believe something has gone wrong with their account. Failing the other
        // way round is strictly better: an unmailed row is invisible, harmless, and expires on its
        // own in an hour.
        $this->requests->save($request);

        // `$request->expiresAt()` is passed rather than recomputed by the adapter, so the deadline
        // the mail states and the deadline the row enforces cannot disagree.
        $this->mailer->sendResetLink($user->email(), $token, $request->expiresAt());

        // SEND BEFORE DISPATCH. `PasswordResetRequested` is an audit fact — "this account was sent a
        // recovery challenge at this time" — and it is only true once the whole use case succeeded.
        // Dispatching before the mailer accepted the message would publish a claim the system might
        // then fail to make good on, and ADR-0008's rule (publish only what has been committed) is
        // the same rule one step further along.
        $this->events->dispatch(...$request->releaseEvents());
    }
}
