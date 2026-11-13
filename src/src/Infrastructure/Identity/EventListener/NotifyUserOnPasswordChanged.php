<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\EventListener;

use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Port\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the "your password was changed" security notice on every `UserPasswordChanged`.
 *
 * NO PORT, NO HANDLER — the first mail in the system built this way, and on purpose. The two other
 * mailers sit behind Domain ports because an Application handler calls them mid-use-case and the
 * Application layer may not import Symfony. Here nothing inside the hexagon decides or sends
 * anything: the event already carries the `UserId` and the instant, the address is one
 * `UserRepository::findById()` away, and the only caller is this Infrastructure listener, which may
 * use Symfony Mailer directly. A port would be an interface with one implementation and no inside
 * client.
 *
 * It subscribes to the **fact**, not to the reset use case, so any future producer (an authenticated
 * change-password screen) is covered without touching this file — which is also why the templates do
 * not state the cause of the change.
 *
 * Like `TwigPasswordResetMailer`: no `->from()` (`mailer.yaml` sets header and envelope sender from
 * `MAILER_FROM`), and an ABSOLUTE_URL, whose host comes from the request today and from
 * `DEFAULT_URI` for any producer that runs outside one.
 */
#[AsEventListener(event: UserPasswordChanged::class)]
final readonly class NotifyUserOnPasswordChanged
{
    public function __construct(
        private UserRepository $users,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserPasswordChanged $event): void
    {
        try {
            $user = $this->users->findById($event->userId());
            if (null === $user) {
                // Not reachable today (the user was saved microseconds ago), and treated like any
                // other failure: no mail, one error line, the reset response untouched.
                $this->logger->error('Could not send the password-changed notice: user not found; the account holder was not told their password changed.', [
                    'user_id' => $event->userId()->toString(),
                ]);

                return;
            }

            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($user->email()->toString())
                    ->subject('Your muzbar password was changed')
                    ->textTemplate('email/password_changed.txt.twig')
                    ->htmlTemplate('email/password_changed.html.twig')
                    ->context([
                        'recipientEmail' => $user->email()->toString(),
                        // From the EVENT — not `Clock::now()`, not `$user->passwordChangedAt()`. The
                        // event is the fact being reported; the three agree today, but only the event
                        // stays right if a second change lands before a slow listener runs.
                        'changedAt' => $event->occurredAt(),
                        'forgotPasswordUrl' => $this->urls->generate('app_forgot_password', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    ]),
            );
        } catch (\Throwable $e) {
            // CATCHES EVERYTHING, RETHROWS NOTHING — the same decision as
            // `IssueVerificationOnUserRegistered`, for a DIFFERENT reason, and the difference matters.
            //
            // Same part: the new password is already committed when this runs (ADR-0008 dispatches
            // after save). An escaping exception undoes nothing and sends nothing; it only turns a
            // successful reset into a 500 — the user retries a now-burnt link and lands on "invalid
            // link" for an account whose password DID change — and aborts the dispatch loop before
            // `UserEmailVerified` reaches its listeners. Catching is strictly better.
            //
            // Different part: the verification listener's catch is justified by a COMPENSATING ACTION
            // (the anonymous resend form). This one has NONE — there is no "resend my security notice",
            // and the account holder cannot ask for a mail they do not know they missed. A lost notice
            // is a lost security signal, so this error line is the only safety net there is, written to
            // be the Sentry alert it will become. ADR-0010's amendment records why that gap is accepted
            // rather than closed with an outbox.
            //
            // `user_id` only, never the address — same reasoning as the verification listener: log
            // identifiers, resolve them at the point of use.
            $this->logger->error('Could not send the password-changed notice; the password change is committed but the account holder was not told their password changed.', [
                'user_id' => $event->userId()->toString(),
                'exception' => $e,
            ]);
        }
    }
}
