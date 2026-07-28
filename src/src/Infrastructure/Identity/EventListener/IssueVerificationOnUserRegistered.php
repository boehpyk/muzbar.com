<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\EventListener;

use App\Application\Identity\Command\RequestEmailVerification;
use App\Application\Identity\Handler\RequestEmailVerificationHandler;
use App\Domain\Identity\Event\UserRegistered;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The listener ADR-0008 predicted, and the one that discharges its "hard / watched" clause.
 *
 * Slice 1 shipped a domain-event mechanism with no load-bearing subscriber and wrote down that the
 * moment `identity-email-verification` made one, the delivery model had to be re-decided rather than
 * inherited. This is that listener; ADR-0010 is that decision. Registration now has a *consequence*
 * — an email — that lives outside the use case that caused it, which is the first time the event
 * bus does real work in this codebase.
 *
 * ONE ATTRIBUTE, NO MAPPING TABLE. `#[AsEventListener(event: UserRegistered::class)]` works because
 * ADR-0008 decision 5 made the domain event's **FQCN its event name**: `SymfonyDomainEventDispatcher`
 * hands the object straight to Symfony's dispatcher, which keys listeners by class. There is no
 * `identity.user_registered` string anywhere, so there is no pair of strings to keep in step and no
 * way to subscribe to an event that does not exist — a typo is a class that does not autoload, which
 * fails loudly at compile time instead of silently never firing. The price is that the event class
 * is now part of the contract between two layers, which is a price worth naming: renaming
 * `UserRegistered` is a breaking change even though nothing about it is public API.
 *
 * WHY REGISTRATION DOES NOT ISSUE THE REQUEST DIRECTLY. `RegisterUserHandler` could call
 * `RequestEmailVerificationHandler` itself in three lines and this class would not exist. It would
 * also make registering a user and mailing them one indivisible operation, so every future
 * registration path — `identity-google-oauth`, an admin-created account, an import — would inherit
 * the mail whether or not it wants it, and the Application layer would grow a dependency on a
 * notification decision. A listener keeps "a user registered" (a fact) and "so send them a link" (a
 * policy) as separate things that separate layers own.
 */
#[AsEventListener(event: UserRegistered::class)]
final readonly class IssueVerificationOnUserRegistered
{
    public function __construct(
        private RequestEmailVerificationHandler $requestEmailVerification,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * CATCHES EVERYTHING AND RETHROWS NOTHING (AC-5, AC-28). This is the decision in the file.
     *
     * By the time this method runs the user row is **already committed** — ADR-0008 dispatches after
     * a successful `save()`, so there is no transaction left to roll back and nothing anybody can do
     * to un-register the account. An exception escaping here therefore does not "fail the
     * registration"; it fails the *response* to a registration that already happened. The visitor
     * gets a 500 and the reasonable conclusion that their signup did not work, then registers again
     * and hits `EmailAlreadyRegistered` on an account they now believe does not exist. That is a
     * strictly worse outcome than the one this catch produces, in which the account exists, the
     * response is the normal redirect, and the missing email is one click away on the resend form.
     *
     * Which is the real point: **the compensating action is the resend form, and that is why the
     * resend form is anonymous and lives in this slice rather than a follow-up.** ADR-0010 chose
     * synchronous event dispatch with no transactional outbox precisely on this argument — a lost
     * event here costs a user one click, so an outbox would be paying real operational complexity to
     * buy back something already bought. Delete the resend form and this catch becomes a silent hole
     * a user cannot climb out of.
     *
     * `\Throwable`, not a list of the four exceptions the handler declares. The declared ones
     * (`InvalidEmail`, `UserNotFound`, `EmailAlreadyVerified`, `TooManyVerificationRequests`) are all
     * genuinely anomalous *here*, unlike on the resend form: the address was just validated and the
     * account was created microseconds ago, so any of them means something is wrong. But the failure
     * this catch actually exists for is the undeclared kind — the SMTP relay refusing the envelope,
     * the Messenger transport unable to reach Postgres, Twig throwing on a template typo. Catching
     * only the declared set would leave exactly the realistic failures uncaught.
     */
    public function __invoke(UserRegistered $event): void
    {
        try {
            ($this->requestEmailVerification)(new RequestEmailVerification($event->email()->toString()));
        } catch (\Throwable $e) {
            // THE USER ID, NOT THE ADDRESS. The address is available — it is right there on the
            // event — and logging it would make this line marginally easier to triage. It is left
            // out anyway, for two reasons that outrank convenience. First, the log is the one place
            // personal data leaks by default: log files are copied to laptops, pasted into issue
            // trackers, shipped to a third-party aggregator when Sentry lands, and retained long
            // after the account is deleted, so an address written here quietly escapes every
            // boundary the application maintains around it. Second, it buys nothing an operator
            // needs: `user_id` joins to `identity_user` in one query for someone who is *supposed*
            // to see the address, so the information is one authorised lookup away rather than
            // pre-scattered across every log consumer. The rule generalises — log identifiers,
            // resolve them at the point of use.
            //
            // ERROR, not warning. A verification mail failing for a brand-new account means either
            // the mail path is down or something in the flow is broken, and both are things a human
            // should be woken up by rather than discover from a support ticket. It is also the level
            // Sentry will pick up when it lands (still the outstanding roadmap carry-over), so this
            // line is written to be the alert it will become.
            //
            // The exception object goes in the context under `exception`, which Monolog understands
            // specially and renders with its class, message and stack trace. `#[\SensitiveParameter]`
            // on `VerifyEmailWithToken::$token` and on `VerificationToken`'s constructor is what
            // keeps a plaintext token out of that trace (AC-2) — the guard is on the value, not
            // here, which is why it holds for every logger in the system and not just this one.
            $this->logger->error('Could not issue an email-verification request for a newly registered user; the account exists and the user must use the resend form.', [
                'user_id' => $event->userId()->toString(),
                'exception' => $e,
            ]);
        }
    }
}
