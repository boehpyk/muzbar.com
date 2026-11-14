<?php

declare(strict_types=1);

namespace App\Application\Identity\Handler;

use App\Application\Identity\Command\SignInWithOAuth;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Exception\EmailAlreadyRegistered;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\InvalidOAuthIdentity;
use App\Domain\Identity\Exception\OAuthEmailNotVerified;
use App\Domain\Identity\Exception\OAuthProviderAlreadyLinked;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Domain\Shared\Port\Clock;
use App\Domain\Shared\Port\DomainEventDispatcher;

/**
 * Turns a provider's claims into an account to sign in: recognise → verified gate → link → provision
 * (ADR-0014).
 *
 * THE ORDER IS THE SECURITY. The subject is looked up first and, when it is linked, wins outright —
 * even if the asserted email changed or is no longer verified at the provider, because the subject
 * is the identifier and the email is a mutable attribute. The email is only consulted on a miss, and
 * only once the provider has vouched for it: that gate is what makes "same mailbox, same person"
 * true, and so what makes linking by email safe at all.
 *
 * TRANSACTION BOUNDARY: one aggregate, at most one `save()`. Unlike the last three slices there is
 * no save-order question, because nothing else changes.
 *
 * IDEMPOTENCY: a replayed callback is stopped by the provider (authorization codes are single-use)
 * before it gets here. Were it to run twice anyway, the second call takes the recognise path.
 */
final readonly class SignInWithOAuthHandler
{
    public function __construct(
        private UserRepository $users,
        private Clock $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    /**
     * Returns the **account's** email — the login identifier — which is not necessarily the one the
     * provider asserted (AC-5: a recognised subject signs in whatever email came with it).
     *
     * @throws InvalidOAuthIdentity
     * @throws OAuthEmailNotVerified
     * @throws InvalidEmail
     * @throws OAuthProviderAlreadyLinked
     * @throws EmailAlreadyRegistered     when two first-time callbacks race to provision one address;
     *                                    a retry then takes the link path
     */
    public function __invoke(SignInWithOAuth $command): Email
    {
        $provider = OAuthProvider::from($command->provider);
        $identity = OAuthIdentity::of($provider, $command->subject);

        // Recognise: no save, no events — signing in is not a change to the account.
        $known = $this->users->findByOAuthIdentity($identity);

        if (null !== $known) {
            return $known->email();
        }

        if (!$command->emailVerified) {
            throw OAuthEmailNotVerified::fromProvider($provider);
        }

        $email = Email::fromString($command->email);
        $now = $this->clock->now();

        $user = $this->users->findByEmail($email);

        if (null !== $user) {
            $user->linkOAuthIdentity($identity, $now);
        } else {
            $user = User::registerWithOAuth($this->users->nextIdentity(), $email, $identity, $now);
        }

        $this->users->save($user);
        $this->events->dispatch(...$user->releaseEvents());

        return $user->email();
    }
}
