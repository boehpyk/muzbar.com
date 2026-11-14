<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Domain\Identity\ValueObject\UserId;

/**
 * Invariant I-28: a `User` holds at most one identity per provider. Raised when an account that
 * already has one Google identity is offered a *different* Google subject for the same address
 * (Workspace "conflicting accounts", AC-11). Linking the same identity again is not this — it is a
 * no-op.
 *
 * The message names the user id and the provider, never either subject.
 */
final class OAuthProviderAlreadyLinked extends \DomainException
{
    public static function forUser(UserId $id, OAuthProvider $provider): self
    {
        return new self(\sprintf('User "%s" already has a different "%s" identity linked.', $id->toString(), $provider->value));
    }
}
