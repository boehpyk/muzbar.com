<?php

declare(strict_types=1);

namespace App\Domain\Identity\Event;

use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\DomainEvent;

/**
 * Raised when `User::linkOAuthIdentity()` attaches a *new* provider identity to an existing account.
 * A repeat link of the same identity records nothing (it is idempotent), and provisioning a brand-new
 * account through a provider records `UserRegistered` instead — the identity is part of how the
 * account came to exist, not a later change to it.
 *
 * `passwordDiscarded` says whether invariant I-29 fired: the account's email was unverified, so its
 * password was dropped. It is on the event so that the deferred *"Google was linked to your account"*
 * notice can say so without any Domain change.
 *
 * WHAT THE PAYLOAD DELIBERATELY OMITS: the **subject** and the **email** (ADR-0008, ADR-0014). The
 * subject is not a secret, but it is a personal identifier and no listener needs it; a listener that
 * wants the address reads it through the repository, like every other event here.
 */
final readonly class UserOAuthIdentityLinked implements DomainEvent
{
    public function __construct(
        private UserId $userId,
        private OAuthProvider $provider,
        private \DateTimeImmutable $linkedAt,
        private bool $passwordDiscarded,
    ) {
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function provider(): OAuthProvider
    {
        return $this->provider;
    }

    public function passwordDiscarded(): bool
    {
        return $this->passwordDiscarded;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
