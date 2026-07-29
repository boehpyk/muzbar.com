<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserPasswordChanged;
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
     * When this account's password was last changed — `null` for an account that still has the one
     * it registered with (invariant I-22).
     *
     * Declared here rather than promoted into the constructor on purpose: it is derived state that
     * no creation path supplies, so a constructor parameter would force `register()` and every
     * future named constructor to pass `null` through a signature that gains nothing from it. Plain
     * `private` with a `null` default, not `readonly`, for the same reason as every other property
     * on this class — see the constructor's note below.
     *
     * IT IS LOAD-BEARING, NOT AUDIT GARNISH, and that distinction is worth spelling out because a
     * "last changed at" column is exactly the kind of field that looks like decoration and gets
     * dropped in a cleanup. Without it, invariant **I-23** — *"a request issued strictly before its
     * user's last password change is not redeemable"* — is not expressible at all, and I-23 is the
     * only thing standing between a lost invalidation write and a **replayable account-takeover
     * primitive**. The reissue sweep (ADR-0011 decision 4) is the primary defence and it can lose a
     * race; this column is the second layer that makes losing that race safe rather than merely
     * unlikely, and it does it with one comparison and no cross-row write. That a future *"your
     * password was changed on X"* notification also reads it is a bonus, not the justification — if
     * it were only the bonus, this would be speculative state and should be cut.
     */
    private ?\DateTimeImmutable $passwordChangedAt = null;

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
     * Replace this account's credential (invariant I-22).
     *
     * It takes a `HashedPassword`, never a string — the same type-system enforcement `register()`
     * relies on: there is no signature on this class through which plaintext could reach the
     * aggregate, so "the Domain never saw the password" is a property of the code's shape rather
     * than of anyone's discipline.
     *
     * THREE THINGS IT DELIBERATELY DOES NOT DO. Each of them is a line someone will eventually want
     * to add, and each would be wrong.
     *
     * 1. **It does not verify the email.** A successful password reset *does* also verify the
     *    address (ADR-0011 decision 6) — but that coupling belongs to *that use case*, not to this
     *    aggregate, and `ResetPasswordWithTokenHandler` calls `verifyEmail()` itself, one line
     *    later, with a comment saying why (AC-31). Putting it here would look like tidying and would
     *    be a security hole: a future authenticated "change my password" screen proves nothing at
     *    all about the mailbox — the person is already logged in, no mail was sent, no link was
     *    clicked — so an aggregate method that verified an address would silently turn that screen
     *    into a verification bypass. The proof came from the *channel*, not from the password
     *    change, and only the caller knows which channel it used.
     *
     * 2. **It does not compare `$at` against the current `passwordChangedAt`.** Invariant I-22
     *    deliberately claims only that the timestamp is `null` until the first change and set
     *    thereafter; it does **not** claim monotonicity, and the omission is the point. Whether
     *    instants arrive in order is a property of the `Clock`, not something this class can enforce
     *    without ordering instants it has no business ordering — it sees one parameter at a time and
     *    has no idea whether two clocks, two processes or two timezone-shifted callers are involved.
     *    Stating the weaker true thing beats stating the stronger false one, and a guard here would
     *    be an unreachable branch that no test could exercise honestly: the only way to reach it is
     *    to hand-build a clock that goes backwards, which proves the guard works and nothing about
     *    the system.
     *
     * 3. **It is not idempotent, and does not need to be** — the sharp contrast with
     *    `verifyEmail()` directly below. Verification is idempotent because mail scanners pre-fetch
     *    links and humans click twice, so a repeat is the *normal* case; a password change is
     *    destructive and repeatable, so a repeat is the *dangerous* case, and it is refused
     *    upstream rather than absorbed here. Two mechanisms do that, in this order: the reset
     *    request is redeemed **before** the user is saved (ADR-0011 decision 5's inverted save
     *    order), so a replayed token is already burnt; and the `passwordChangedAt` staleness guard
     *    (I-23) catches the pathological case where an invalidation write was lost. An
     *    "already-changed" short-circuit here would be strictly worse than either — it would have
     *    to guess what "already" means for an operation whose whole nature is to happen again.
     *
     * Every successful call records `UserPasswordChanged`, on every call, because every change is a
     * new fact rather than a re-announcement of an old one.
     */
    public function changePassword(HashedPassword $newHash, \DateTimeImmutable $at): void
    {
        $this->passwordHash = $newHash;
        $this->passwordChangedAt = $at;
        $this->recordThat(new UserPasswordChanged($this->id, $at));
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
     * `null` means "never changed since registration", which is a different statement from
     * "changed at the moment of registration" — and the difference is why this is nullable rather
     * than defaulted to `registeredAt`. `ResetPasswordWithTokenHandler` reads it for invariant I-23
     * and treats `null` as "no request can be stale", which is exactly right: an account whose
     * password has never changed has nothing for an outstanding request to be older than.
     */
    public function passwordChangedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
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
