<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Identity\Port\VerificationMailer;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\VerificationToken;

/**
 * A test double for the `VerificationMailer` port that records every call instead of sending mail.
 *
 * `RequestEmailVerificationHandler` is the only place in the system that legitimately holds a
 * plaintext `VerificationToken` (see its own docblock), so it is also the only place a test can
 * observe one without going around the Domain's guards — `VerificationToken` deliberately has no
 * `__toString()` and masks itself under `var_dump()`/`print_r()`. Recording the token object here
 * (never printing it, never persisting it) is what lets
 * `RequestEmailVerificationHandlerTest` assert the handler mailed *something* shaped like a real
 * token, addressed to the right person, with the deadline the stored request agrees with — the
 * technical plan's "a fake mailer recording the plaintext token so the test can build the URL".
 */
final class RecordingVerificationMailer implements VerificationMailer
{
    /**
     * @var list<array{recipient: Email, token: VerificationToken, expiresAt: \DateTimeImmutable}>
     */
    private array $sent = [];

    public function sendVerificationLink(Email $recipient, VerificationToken $token, \DateTimeImmutable $expiresAt): void
    {
        $this->sent[] = ['recipient' => $recipient, 'token' => $token, 'expiresAt' => $expiresAt];
    }

    public function count(): int
    {
        return \count($this->sent);
    }

    public function lastRecipient(): Email
    {
        return $this->last()['recipient'];
    }

    public function lastToken(): VerificationToken
    {
        return $this->last()['token'];
    }

    public function lastExpiresAt(): \DateTimeImmutable
    {
        return $this->last()['expiresAt'];
    }

    /**
     * @return array{recipient: Email, token: VerificationToken, expiresAt: \DateTimeImmutable}
     */
    private function last(): array
    {
        $last = $this->sent[\count($this->sent) - 1] ?? null;

        if (null === $last) {
            throw new \LogicException('No verification link was ever sent through this double.');
        }

        return $last;
    }
}
