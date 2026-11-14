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
 * The message names the user id and the provider, never either subject. `userId` is exposed so a
 * caller can log the account (the failure contract's `user_id`) without parsing the message.
 */
final class OAuthProviderAlreadyLinked extends \DomainException
{
    private function __construct(public readonly UserId $userId, string $message)
    {
        parent::__construct($message);
    }

    public static function forUser(UserId $id, OAuthProvider $provider): self
    {
        return new self($id, \sprintf('User "%s" already has a different "%s" identity linked.', $id->toString(), $provider->value));
    }
}
