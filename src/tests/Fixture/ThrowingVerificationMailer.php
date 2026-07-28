<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Port\VerificationMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;

/**
 * A test double for the `VerificationMailer` port that always throws, standing in for a transport
 * refusing the message (a down SMTP relay, an unreachable Messenger backend).
 *
 * Used by `RegistrationControllerTest` (AC-5, AC-28) to prove that a registration whose verification
 * mail fails to send still commits the `User` row and still returns the normal redirect —
 * `IssueVerificationOnUserRegistered` catches `\Throwable` precisely so a failure this deep never
 * turns an already-successful registration into a 500. Bound over the real
 * `App\Domain\Identity\Port\VerificationMailer` service via
 * `self::getContainer()->set(VerificationMailer::class, new ThrowingVerificationMailer())` — the
 * mechanism `framework.test: true` exists to allow — rather than trying to fail the real SMTP
 * transport, which would test Mailpit's reachability rather than this application's error handling.
 */
final class ThrowingVerificationMailer implements VerificationMailer
{
    public function sendVerificationLink(Email $recipient, VerificationToken $token, \DateTimeImmutable $expiresAt): void
    {
        throw new \RuntimeException('Simulated verification-mail transport failure (test double).');
    }
}
