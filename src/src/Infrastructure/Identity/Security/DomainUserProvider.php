<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Symfony Security's window onto the `Identity` context.
 *
 * It talks to the `UserRepository` **port**, not to Doctrine — which is what makes the whole
 * authentication stack indifferent to where users are stored. Swapping Postgres for anything else
 * is a change to one adapter and none to this class.
 *
 * DELIBERATELY NOT A `PasswordUpgraderInterface`. Implementing it would make Symfony re-hash a
 * user's password on login whenever the configured algorithm's cost changed. That is a real
 * feature, and it is an explicit non-goal of this slice: it needs a persistence write on a read
 * path, a Domain method to replace the hash, and a decision about what happens when the write
 * fails mid-login. `identity-password-reset` is where that conversation belongs.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class DomainUserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        try {
            $email = Email::fromString($identifier);
        } catch (InvalidEmail $e) {
            // A malformed login identifier is a failed login, not a server error. Anybody can post
            // `_username=<script>` to /login; letting `InvalidEmail` escape would turn that into a
            // 500 and a stack trace in the logs for every passing scanner. Translating it to the
            // framework's own "not found" also keeps the response byte-identical to the
            // wrong-password case (AC-13) — a distinct error page would be a free oracle telling an
            // attacker which identifiers are even shaped like ours.
            throw new UserNotFoundException(previous: $e);
        }

        $user = $this->users->findByEmail($email);

        if (null === $user) {
            throw new UserNotFoundException();
        }

        return SecurityUser::fromDomainUser($user);
    }

    /**
     * Reloads the session's user from storage on every request.
     *
     * BY ID, NOT BY EMAIL — see `SecurityUser::id()`. It also means the answer to "this account was
     * deleted mid-session" is a `UserNotFoundException`, which Symfony turns into a logout: state
     * that no longer exists cannot keep authenticating requests.
     */
    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $refreshed = $this->users->findById(UserId::fromString($user->id()));

        if (null === $refreshed) {
            throw new UserNotFoundException();
        }

        return SecurityUser::fromDomainUser($refreshed);
    }

    public function supportsClass(string $class): bool
    {
        // Exact match rather than `is_a()`: `SecurityUser` is final, so a subclass cannot exist, and
        // claiming support for one we have never seen would be a lie the firewall acts on.
        return SecurityUser::class === $class;
    }
}
