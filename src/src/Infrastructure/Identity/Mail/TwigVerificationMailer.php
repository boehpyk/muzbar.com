<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Mail;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Port\VerificationMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns "tell this person how to prove their address" into an actual message.
 *
 * Everything the `VerificationMailer` port refused to name is decided in this file, and the list is
 * worth reading as a definition of what an adapter is *for*: the route and therefore the shape of a
 * link, the router that builds it, the subject line, the two Twig templates, the fact that there are
 * two, and the transport. None of it is expressible in the Domain without importing a framework,
 * which is the tell that none of it was ever the Domain's business. The port's whole surface is
 * `(Email, VerificationToken, \DateTimeImmutable)`; this class is the rest of the iceberg.
 *
 * THE FOOTGUN THIS CLASS EXISTS TO WALK INTO (AC-6). URL generation needs a scheme and a host, and
 * inside an HTTP request Symfony takes them from the incoming request — free, correct, invisible.
 * This class does not run inside an HTTP request in the case that matters. Mail is routed through
 * Messenger (ADR-0010), so `MailerInterface::send()` queues a `SendEmailMessage` and the **worker
 * process** renders and delivers it, with no request context whatsoever; `bin/console` and any
 * future Scheduler task are in the same position. With no request to ask, `UrlGenerator` falls back
 * to `framework.router.default_uri`, which is `DEFAULT_URI` and whose Symfony-skeleton default is
 * `http://localhost`.
 *
 * The consequence is uniquely nasty: every verification link in production points at `localhost`,
 * the flow is completely broken for every real user, and **no test catches it**, because a
 * functional test runs inside a simulated request where the fallback never fires. It is a bug that
 * is invisible in dev, invisible in CI, and total in production. T2 therefore set `DEFAULT_URI` per
 * environment (`http://localhost:8080` in dev, `https://muzbar.com` in prod) and the test plan
 * insists on one assertion that renders this mail with no request context at all. This class is why
 * both of those exist — if you are here wondering whether that env var is load-bearing, it is.
 *
 * WHAT IS DELIBERATELY ABSENT: no `->from(...)`. The sender comes from `config/packages/mailer.yaml`,
 * which sets both `framework.mailer.envelope.sender` (the invisible bounce address) and
 * `framework.mailer.headers.From` (what a human sees) from the single `MAILER_FROM` env var. Setting
 * it here would override the header while leaving the envelope alone, producing exactly the
 * From/return-path mismatch that mail.yaml's comment explains is a classic spam signal — and it
 * would put a configured value in code, which CLAUDE.md forbids for its own reasons. One sender,
 * one place, applied by the transport to every message the application sends.
 *
 * There is also no error handling. A transport that refuses the message throws, the exception
 * travels up through the handler to `IssueVerificationOnUserRegistered`, and *that* class decides
 * that a failed mail must not break an already-committed registration (AC-5). An adapter swallowing
 * the error here would take that decision away from the only class positioned to make it, and would
 * report success for a message that never left.
 */
final readonly class TwigVerificationMailer implements VerificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function sendVerificationLink(Email $recipient, VerificationToken $token, \DateTimeImmutable $expiresAt): void
    {
        // `reveal()` is the deliberately uncomfortable reader on `VerificationToken`, and this is
        // one of its only two sanctioned call sites (the other being the token generator, which
        // digests it). The plaintext exists in this method's frame, goes into the URL string, goes
        // into the message, and is never returned, logged or stored.
        //
        // ABSOLUTE_URL, not the default ABSOLUTE_PATH. A path-only link is meaningless in an email:
        // there is no page it is relative to. See the class docblock for where the host comes from
        // when nobody is holding a request.
        $verificationUrl = $this->urls->generate(
            'app_verify_email',
            ['token' => $token->reveal()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = (new TemplatedEmail())
            ->to($recipient->toString())
            ->subject('Confirm your email address')

            // BOTH PARTS, AND THE TEXT ONE IS NOT OPTIONAL (PRD validation #3). The reflex is to
            // ship HTML and call the plain-text alternative a nicety for people using mutt. It is
            // not: a `multipart/alternative` message with only an HTML part is one of the oldest and
            // strongest spam signals there is, because bulk senders skip the text part and
            // legitimate mailers do not. Spam filters read the text part — it is the version they
            // can score without executing anything — so for a message whose entire purpose is to
            // arrive, this is deliverability work, not accessibility garnish. It also has to *say
            // the same thing*: a text part that omits the link, or carries a different one, is
            // worse than none.
            //
            // `TemplatedEmail` + Twig-bridge's `BodyRenderer` renders both at send time, which
            // matters more than it looks: the rendering happens in the worker, so a Twig error is a
            // failed queue message rather than a 500 on the registration request.
            ->htmlTemplate('email/verify_email.html.twig')
            ->textTemplate('email/verify_email.txt.twig')
            ->context([
                'verificationUrl' => $verificationUrl,

                // The recipient's own address, which is the *only* address either template renders
                // (AC-4, AC-32). It earns its place: a person with two mailboxes forwarding into
                // one needs to know which account this is about, and a person who did not register
                // needs to see whose address was used before deciding to ignore it.
                'recipientEmail' => $recipient->toString(),

                // `$expiresAt` is passed by the port rather than recomputed here, so the message and
                // the stored row can never disagree about the deadline — and the *duration* is
                // derived from the aggregate's own constant rather than a hardcoded "24 hours" in a
                // template, so changing `LIFETIME_SECONDS` cannot leave the mail lying. Rendering
                // both the human duration and the absolute instant is deliberate: "24 hours" is what
                // someone reading at breakfast acts on, and the timestamp is what someone reading at
                // 23:58 needs.
                'expiresAt' => $expiresAt,
                'lifetimeHours' => intdiv(EmailVerificationRequest::LIFETIME_SECONDS, 3600),
            ]);

        // One line, and it means something different depending on config, which is the point.
        // `messenger.yaml` routes `SendEmailMessage` to the `async` transport, so in dev and prod
        // this returns as soon as a row lands in `messenger_messages` and the worker does the real
        // work; under `APP_ENV=test` the same message is routed to `sync` so assertions can see it.
        // This class knows nothing about any of that — which is exactly the property that let
        // ADR-0010 change the delivery model without touching a line of it.
        $this->mailer->send($message);
    }
}
