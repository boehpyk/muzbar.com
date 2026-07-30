<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Port\PasswordResetMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\ResetToken;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A test double for the `PasswordResetMailer` port that always throws a genuine
 * `Symfony\Component\Mailer\Exception\TransportException` — the exact interface
 * (`TransportExceptionInterface`, aliased `MailTransportFailure` at the only place that names it,
 * `PasswordResetController::request()`) that catch arm exists to close.
 *
 * DELIBERATELY NOT `ThrowingVerificationMailer`'s `\RuntimeException`. That fixture stands in for a
 * failure `IssueVerificationOnUserRegistered` swallows with a bare `catch (\Throwable)`, so any
 * exception type proves its point. Here the point is the *opposite*: `PasswordResetController`
 * catches two **specific** types (`MailTransportFailure`/`TransportExceptionInterface` and
 * `QueueTransportFailure`/Messenger's `TransportException`) and nothing broader — a bare
 * `\RuntimeException` would miss that catch arm entirely, fall through uncaught, and 500 the
 * request, which would make a test built on it prove nothing about the code it is meant to exercise.
 *
 * Bound over the real `App\Infrastructure\Identity\Mail\TwigPasswordResetMailer` service (the
 * **concrete class id**, never the `PasswordResetMailer` port alias — see
 * `ForgotPasswordTest`'s own test for why) via
 * `self::getContainer()->set(TwigPasswordResetMailer::class, new ThrowingPasswordResetMailer())`,
 * mirroring `ThrowingVerificationMailer`'s own convention.
 */
final class ThrowingPasswordResetMailer implements PasswordResetMailer
{
    public function sendResetLink(Email $recipient, ResetToken $token, \DateTimeImmutable $expiresAt): void
    {
        throw new TransportException('Simulated password-reset mail transport failure (test double).');
    }
}
