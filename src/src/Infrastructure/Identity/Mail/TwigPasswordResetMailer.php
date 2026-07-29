<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Mail;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\PasswordResetMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\ResetToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns "tell this person how to set a new password" into an actual message.
 *
 * Everything the `PasswordResetMailer` port refused to name is decided here, and the list is worth
 * reading as a definition of what an adapter is *for*: the route and therefore the shape of a link,
 * the router that builds it, the subject line, the two Twig templates, the fact that there are two,
 * the wording of the reassurance, and the transport. None of it is expressible in the Domain without
 * importing a framework, which is the tell that none of it was ever the Domain's business. The
 * port's whole surface is `(Email, ResetToken, \DateTimeImmutable)`; this class is the rest of the
 * iceberg — note in particular that **the Domain never sees a URL**, it hands over a token and a
 * deadline and this class decides what a link is.
 *
 * THE FOOTGUN THIS CLASS EXISTS TO WALK INTO, RESTATED RATHER THAN CROSS-REFERENCED (AC-11). URL
 * generation needs a scheme and a host. Inside an HTTP request Symfony takes both from the incoming
 * request — free, correct, invisible — and this class does not run inside an HTTP request in the
 * case that matters. Mail goes through Messenger (ADR-0010), so `MailerInterface::send()` only
 * queues a `SendEmailMessage`; the **worker process** renders and delivers it, with no request
 * context whatsoever, and `bin/console` and any future Scheduler task are in the same position. With
 * no request to ask, `UrlGenerator` falls back to `framework.router.default_uri`, i.e. `DEFAULT_URI`,
 * whose Symfony-skeleton value is `http://localhost`.
 *
 * `TwigVerificationMailer` already carries this warning and repeating it is deliberate, because the
 * consequence is worse on this flow than on that one. There, an unreachable link means a new account
 * stays unverified and the person can register attention some other way. Here it means **an account
 * cannot be recovered at all** — the link is the entire recovery path, and the people clicking it
 * are by definition already locked out. And the failure is invisible everywhere you would look for
 * it: total in production, correct in dev (where a request is usually in flight), and **uncatchable
 * by any functional test**, because a test runs inside a simulated request where the fallback never
 * fires. Slice 2 set `DEFAULT_URI` per environment (`http://localhost:8080` in dev,
 * `https://muzbar.com` in prod). If you are here wondering whether that env var is load-bearing: it
 * is the difference between a working password reset and a support queue.
 *
 * WHAT IS DELIBERATELY ABSENT: no `->from(...)`. The sender comes from `config/packages/mailer.yaml`,
 * which drives both `framework.mailer.envelope.sender` (the invisible bounce address) and
 * `framework.mailer.headers.From` (what a human sees) from the single `MAILER_FROM` env var. Setting
 * it here would override the header while leaving the envelope alone, producing exactly the
 * From/return-path mismatch that file's comment explains is a classic spam signal — on the one
 * message in the system that absolutely has to reach the inbox. It would also put a configured value
 * in code, which CLAUDE.md forbids for its own reasons.
 *
 * There is also **no error handling**. A transport that refuses the message throws, and the exception
 * travels up to `RequestPasswordResetHandler`, which is the only class positioned to decide what a
 * failed send means for an already-persisted request. Swallowing it here would take that decision
 * away and report success for a message that never left — the worst possible outcome for a flow
 * whose user is sitting in front of an inbox waiting.
 */
final readonly class TwigPasswordResetMailer implements PasswordResetMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function sendResetLink(Email $recipient, ResetToken $token, \DateTimeImmutable $expiresAt): void
    {
        // `reveal()` is the deliberately uncomfortable reader on `ResetToken`, and this is one of its
        // only two sanctioned call sites (the other being `RandomResetTokenGenerator`, which digests
        // it). The plaintext exists in this method's frame, goes into the URL string, goes into the
        // message, and is never returned, logged or stored. It is emphatically *not* put into the
        // domain event — see the port's docblock for why that elegant-looking wiring was rejected.
        //
        // ABSOLUTE_URL, not the default ABSOLUTE_PATH. A path-only link is meaningless in an email:
        // there is no page it is relative to. See the class docblock for where the host comes from
        // when nobody is holding a request.
        $resetUrl = $this->urls->generate(
            'app_reset_password_check',
            ['token' => $token->reveal()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = (new TemplatedEmail())
            ->to($recipient->toString())

            // Plain and unmistakable, and not translated — same as the verification mail, because
            // there is no translation layer in the application yet and a `trans()` here would be the
            // only translated string in the system. A reset mail's subject is read in a crowded
            // inbox by someone who is either expecting it or alarmed by it, and both of those people
            // are served by saying exactly what it is.
            ->subject('Reset your muzbar password')

            // BOTH PARTS, AND THE TEXT ONE IS NOT OPTIONAL (PRD validation #3). The reflex is to ship
            // HTML and treat the plain-text alternative as a courtesy for people using mutt. It is
            // not: a `multipart/alternative` message carrying only an HTML part is one of the oldest
            // and strongest spam signals there is, because bulk senders skip the text part and
            // legitimate mailers do not. Spam filters read the text part — it is the version they can
            // score without executing anything. For a message whose entire purpose is to arrive, and
            // whose non-arrival means an account is unrecoverable, this is deliverability work rather
            // than accessibility garnish. It also has to *say the same thing*: a text part that omits
            // the link, or carries a different one, is worse than none.
            //
            // `TemplatedEmail` + the Twig bridge's `BodyRenderer` render both at send time, which
            // matters more than it looks: rendering happens in the worker, so a Twig error is a
            // failed queue message rather than a 500 on the forgot-password request — which would
            // itself be an oracle, since the neutral response only stays neutral if nothing throws
            // differently for a known address.
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context([
                'resetUrl' => $resetUrl,

                // The recipient's own address, and the only address either template renders. It
                // earns its place for the same two reasons as the verification mail: someone with
                // several mailboxes forwarding into one needs to know which account this is about,
                // and someone who did **not** ask for a reset needs to see whose address was used
                // before deciding to ignore it. Nothing else about the account appears — no
                // username, no hint about the current password, and obviously no credential.
                'recipientEmail' => $recipient->toString(),

                // `$expiresAt` is passed by the port rather than recomputed here, so the message and
                // the stored row can never disagree about the deadline. A mail that promises an hour
                // over a row that expired forty minutes ago is a support ticket the design can just
                // prevent.
                'expiresAt' => $expiresAt,

                // ...and the *duration* is derived from the aggregate's own constant rather than
                // typed into a template, so changing `LIFETIME_SECONDS` cannot leave the mail lying.
                // Rendering both the duration and the absolute instant is deliberate: "1 hour" is
                // what someone reading at their desk acts on, and the timestamp is what someone who
                // opens the mail two hours later needs in order to understand why the link is dead.
                'lifetime' => self::describeLifetime(PasswordResetRequest::LIFETIME_SECONDS),
            ]);

        // One line that means something different depending on config, which is the point.
        // `messenger.yaml` routes `SendEmailMessage` to the `async` transport, so in dev and prod
        // this returns as soon as a row lands in `messenger_messages` and the worker does the real
        // work; under `APP_ENV=test` the same message goes to `sync` so assertions can see it. This
        // class knows nothing about any of that.
        $this->mailer->send($message);
    }

    /**
     * The lifetime as a phrase a human reads, computed here rather than in Twig.
     *
     * WHY THIS EXISTS AT ALL, given that `TwigVerificationMailer` just passes an integer number of
     * hours and lets the template write the word "hours" after it. Because that trick only survives
     * a 24-hour lifetime. This one is 3600 seconds, so the same `intdiv(…, 3600)` would render
     * "expires in 1 hours"; and if `LIFETIME_SECONDS` were ever tightened to, say, 1800 — which
     * ADR-0011 decision 3 explicitly weighs and could revisit — it would render "expires in 0
     * hours", which is not merely ungrammatical but **false**, in the one sentence of the message a
     * locked-out user acts on. The constant is the source of truth precisely so the mail cannot lie
     * about it, and a formatting scheme that breaks when the constant changes gives that guarantee
     * away.
     *
     * It lives in PHP rather than in the templates because there are two of them and the pluralising
     * expression would have to be identical in both — two copies of a rule that can drift while both
     * files stay green. One method, one behaviour, both parts rendering the same words.
     */
    private static function describeLifetime(int $seconds): string
    {
        if (0 === $seconds % 3600) {
            $hours = intdiv($seconds, 3600);

            return \sprintf('%d %s', $hours, 1 === $hours ? 'hour' : 'hours');
        }

        $minutes = (int) ceil($seconds / 60);

        return \sprintf('%d %s', $minutes, 1 === $minutes ? 'minute' : 'minutes');
    }
}
