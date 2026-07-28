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
     * @param bool             $usable       `User::isUsable()` at the moment this snapshot was taken
     */
    private function __construct(
        private string $id,
        private string $email,
        private array $roles,
        private string $passwordHash,
        private bool $usable,
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

            // `isUsable()`, NOT `isEmailVerified()` — and the difference is the entire point of the
            // line. The two methods return the same boolean today, so picking either one passes
            // every test in the suite. They stop agreeing in `identity-google-oauth`, which widens
            // `User::isUsable()` to `isEmailVerified() || hasVerifiedOAuthIdentity()` *inside the
            // aggregate*, exactly where ADR-0005's invariant I-5 lives. Read `isUsable()` here and
            // `VerifiedAccountUserChecker` inherits that widening for free, with no Infrastructure
            // diff at all. Read `isEmailVerified()` and the checker silently keeps refusing users
            // who signed up with Google and have no password to verify an address with — a bug that
            // ships in a slice whose diff does not contain this file.
            //
            // The general rule worth taking away: an adapter should copy the *decision* the Domain
            // exposes, never re-derive it from the facts underneath. `isUsable()` is the decision;
            // `emailVerifiedAt` is a fact it happens to be made of today.
            $user->isUsable(),
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
     * Whether the account behind this snapshot may authenticate at all — ADR-0005's invariant I-5,
     * carried across the layer boundary so `VerifiedAccountUserChecker` can enforce it without
     * reaching into the Domain from a `UserCheckerInterface`.
     *
     * NO STALENESS WINDOW, AND IT IS WORTH KNOWING WHY RATHER THAN HOPING. This object is
     * `readonly`, serialised into the session, and potentially sits in Redis for two weeks — which
     * would normally make a cached authorisation flag a real hazard: an account verified (or, later,
     * disabled) on Tuesday would keep answering with Monday's boolean until the session expired.
     * It cannot happen here because `DomainUserProvider::refreshUser()` reloads the aggregate by
     * `UserId` and rebuilds this whole object on **every** request that touches the firewall, so the
     * flag is never older than the request reading it. That is also precisely why `refreshUser()`
     * needed no change for this slice: a provider that reloads state gets new state for free, and
     * one that patched fields onto a cached object would have needed one more line per field
     * forever.
     */
    public function isUsable(): bool
    {
        return $this->usable;
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
