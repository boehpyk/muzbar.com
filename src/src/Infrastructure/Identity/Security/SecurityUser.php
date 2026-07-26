<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Security;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\ValueObject\Role;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The adapter that presents a Domain `User` to Symfony Security.
 *
 * WHY THE AGGREGATE DOES NOT IMPLEMENT `UserInterface` ITSELF (AC-29). It would be one line and it
 * would delete this class — and that is exactly the trade nobody should take. The moment an
 * aggregate implements a framework interface, the framework's idea of a user starts dictating the
 * business model: `getRoles()` must return strings rather than the `Role` enum, `eraseCredentials()`
 * appears on the aggregate's public surface and then gets deprecated out from under it, and the
 * next Symfony major becomes a Domain refactor. The dependency arrow has to point inwards, so the
 * translation happens here, in Infrastructure, where a framework upgrade belongs.
 *
 * It is `readonly` because it is a snapshot, not a live handle. Symfony serialises this object into
 * the session; a mutable copy of an aggregate sitting in Redis for two weeks would be a second,
 * silently diverging source of truth. Anything that needs current state reloads through
 * `DomainUserProvider::refreshUser()`.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param non-empty-string $email        the login identifier
     * @param list<string>     $roles        `Role` backed values, which are literally what Symfony expects
     * @param string           $passwordHash the opaque hash the firewall verifies against
     */
    private function __construct(
        private string $id,
        private string $email,
        private array $roles,
        private string $passwordHash,
    ) {
    }

    public static function fromDomainUser(User $user): self
    {
        $email = $user->email()->toString();

        // `Email::fromString()` runs `FILTER_VALIDATE_EMAIL`, so an empty address cannot exist —
        // but PHPStan cannot see through `filter_var`, and `UserInterface::getUserIdentifier()`
        // is declared `non-empty-string`. The assertion is how that guarantee crosses the layer
        // boundary without weakening the interface's contract.
        \assert('' !== $email);

        return new self(
            $user->id()->toString(),
            $email,
            array_map(static fn (Role $role): string => $role->value, $user->roles()),
            $user->passwordHash()->toString(),
        );
    }

    /**
     * The stable identity, carried so `refreshUser()` can reload by id rather than by email.
     *
     * Email is the *login* identifier and it is mutable in principle (an email-change feature is a
     * later slice). Refreshing by a mutable key means that the moment a user changes their address
     * every existing session of theirs silently fails to refresh and logs out. Reloading by
     * `UserId` — which never changes — makes that whole class of bug unreachable.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * A no-op, because there is nothing to erase: this object holds a hash and never a plaintext
     * password. `PlainPassword` is destroyed with the registration handler's stack frame and never
     * reaches Security at all.
     *
     * The `#[\Deprecated]` attribute is load-bearing rather than decorative. Symfony 7.3 deprecated
     * *implementing* this method, and `AuthenticatorManager::checkEraseCredentials()` reflects on
     * it: an implementation without the attribute triggers a deprecation on every login, and one
     * with it is skipped entirely. `UserInterface` still declares the method in 7.4.14, so it
     * cannot simply be dropped — the attribute is the sanctioned way to say "empty on purpose".
     *
     * @deprecated since Symfony 7.3
     */
    #[\Deprecated(since: 'symfony/security-core 7.3')]
    public function eraseCredentials(): void
    {
    }
}
