<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\CannotRevokeBaseRole;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\RecordsEvents;

/**
 * The `Identity` aggregate root: one person's account on muzbar.
 *
 * It is the consistency boundary for its own credentials, roles and verification state — and it is
 * kept small on purpose. `OAuthIdentity` joins it as a collection in `identity-google-oauth`;
 * `ApiKey` is a *separate* aggregate in Phase 3, because an API key has its own lifecycle and
 * nothing about revoking one needs to be transactionally consistent with a user's roles.
 *
 * Two things this class deliberately does **not** do:
 *
 * - It does not implement any framework interface. Symfony Security talks to `SecurityUser`, an
 *   Infrastructure adapter built from this object. The moment an aggregate implements
 *   `UserInterface`, the framework's idea of a user starts dictating the business model.
 * - It never calls `new \DateTimeImmutable()`. Every timestamp arrives as a parameter, sourced
 *   from the `Clock` port by the Application handler. That is what makes "registered at exactly
 *   this instant" an assertable fact in a test instead of a race against the wall clock.
 */
final class User
{
    use RecordsEvents;

    /**
     * Properties are plain `private`, not `readonly`, even where nothing ever reassigns them
     * (`id`, `registeredAt`): Doctrine hydrates this object by reflection and readonly properties
     * still trip its refresh/proxy paths. Immutability from the outside is guaranteed by the
     * private constructor and the total absence of setters, which is the guarantee that matters.
     *
     * @param list<Role> $roles
     */
    private function __construct(
        private UserId $id,
        private Email $email,
        private HashedPassword $passwordHash,
        private array $roles,
        private ?\DateTimeImmutable $emailVerifiedAt,
        private \DateTimeImmutable $registeredAt,
    ) {
    }

    /**
     * The one and only way a User comes into existence.
     *
     * A named creation method rather than a public constructor because "register" is a business
     * event with a meaning, while `new User(...)` is a memory allocation with none. It takes a
     * `HashedPassword`, never a string — invariant I-2 enforced by the type system: there is no
     * signature here through which plaintext could reach the aggregate.
     *
     * Every registration yields exactly `[Role::User]`. Elevated roles are granted deliberately,
     * never chosen by whoever filled in the form (AC-20).
     */
    public static function register(UserId $id, Email $email, HashedPassword $passwordHash, \DateTimeImmutable $registeredAt): self
    {
        $user = new self($id, $email, $passwordHash, [Role::User], null, $registeredAt);
        $user->recordThat(new UserRegistered($id, $email, $registeredAt));

        return $user;
    }

    /**
     * Idempotent by design (invariant I-4), and the idempotency lives *here* rather than in the
     * handler or the controller because "a verified email cannot be verified twice" is a rule
     * about the aggregate, not about any one caller. `identity-email-verification` will expose
     * this as a link that users click twice and that mail clients pre-fetch, so every future
     * adapter inherits the guarantee for free — and, crucially, a repeat records **no** event, so
     * no listener ever sees the same fact twice.
     *
     * There is no `unverifyEmail()`: the timestamp moves `null → instant` exactly once, forever.
     */
    public function verifyEmail(\DateTimeImmutable $at): void
    {
        if (null !== $this->emailVerifiedAt) {
            return;
        }

        $this->emailVerifiedAt = $at;
        $this->recordThat(new UserEmailVerified($this->id, $at));
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /**
     * ADR-0005's invariant I-5 — *"a usable account has a verified email or a linked verified
     * OAuth identity"* — in executable form.
     *
     * It reads trivially today because the second half of the sentence has no model yet.
     * `identity-google-oauth` widens it to `isEmailVerified() || hasVerifiedOAuthIdentity()` by
     * adding one clause *inside this method*, which is the whole reason it exists now: the
     * aggregate's design never has to reopen, and no caller changes.
     *
     * ENFORCEMENT IS DEFERRED, ON PURPOSE (AC-24). This slice installs no `UserCheckerInterface`
     * on the firewall, so an unverified user can still log in: the Domain holds the opinion, the
     * Security layer does not yet act on it — and per ADR-0005 the Security layer is exactly where
     * authentication policy belongs. `identity-email-verification` adds the checker and inverts
     * AC-24 without touching a line of `Domain` or `Application`.
     */
    public function isUsable(): bool
    {
        return $this->isEmailVerified();
    }

    /**
     * De-duplicating, so granting twice is a no-op rather than a corrupted role list (I-3).
     */
    public function grantRole(Role $role): void
    {
        if (\in_array($role, $this->roles, true)) {
            return;
        }

        $this->roles[] = $role;
    }

    /**
     * `Role::User` is the floor: an account that holds no role at all is not a lesser account, it
     * is an unusable one, and silently allowing that would turn an authorisation bug into a data
     * state. Refusing loudly keeps invariant I-3 true by construction.
     */
    public function revokeRole(Role $role): void
    {
        if (Role::User === $role) {
            throw CannotRevokeBaseRole::forRole($role);
        }

        // Re-indexed: `array_filter` preserves keys, and a gap would break the `list<Role>` shape
        // that the JSON mapping and PHPStan both rely on.
        $this->roles = array_values(array_filter($this->roles, static fn (Role $held): bool => $held !== $role));
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function passwordHash(): HashedPassword
    {
        return $this->passwordHash;
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function emailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function registeredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }
}
