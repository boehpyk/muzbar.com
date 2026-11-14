<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserOAuthIdentityLinked;
use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Event\UserRegistered;
use App\Domain\Identity\Exception\CannotRevokeBaseRole;
use App\Domain\Identity\Exception\OAuthProviderAlreadyLinked;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use App\Domain\Shared\Event\RecordsEvents;

/**
 * The `Identity` aggregate root: one person's account on muzbar.
 *
 * It is the consistency boundary for its own credentials, roles and verification state — and it is
 * kept small on purpose. Linked `OAuthIdentity` values live inside it (ADR-0014), because the
 * rules about them — one per provider (I-28), and linking discarding an unproven password (I-29) —
 * are rules about this user's credentials changing together. `ApiKey` is a *separate* aggregate in Phase 3, because an API key has its own lifecycle and
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
     * it registered with, *or never had one* (a Google-provisioned account; invariant I-22). Setting
     * a first password is a change like any other, and dropping one under I-29 is not a change at
     * all: nobody chose a new password, so this stays untouched.
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
     * The provider identities this account can sign in with (ADR-0014): at most one per provider
     * (I-28), and together with the password at least one credential exists (I-27).
     *
     * Declared here, outside the constructor, for the same reason as `passwordChangedAt`: `register()`
     * has nothing to supply, and the one creation path that does (`registerWithOAuth()`) sets it
     * after construction.
     *
     * @var list<OAuthIdentity>
     */
    private array $oauthIdentities = [];

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
        private ?HashedPassword $passwordHash,
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
     * The second way a User comes into existence: through a provider, with no password at all.
     *
     * PRECONDITION, ENFORCED BY THE CALLER: the provider verified `$email`. That is why the account is
     * born email-verified (`emailVerifiedAt = $registeredAt`) — the proof already happened, at the
     * provider — and why invariant I-5 holds from the first instant. The aggregate cannot check a
     * boolean it never sees; `SignInWithOAuthHandler` refuses an unverified address before calling
     * this.
     *
     * I-27 (a password, an identity, or both) is held by the signature: there is no way to call this
     * without an identity.
     *
     * Records `UserRegistered` then `UserEmailVerified`, in that order — the same two facts a
     * password registration followed by a verification click would record. There is deliberately no
     * `UserRegisteredWithOAuth`: the fact is "a user registered", and each listener decides whether
     * it applies (the verification-mail listener treats an already-verified account as nothing to
     * do).
     */
    public static function registerWithOAuth(UserId $id, Email $email, OAuthIdentity $identity, \DateTimeImmutable $registeredAt): self
    {
        $user = new self($id, $email, null, [Role::User], $registeredAt, $registeredAt);
        $user->oauthIdentities = [$identity];
        $user->recordThat(new UserRegistered($id, $email, $registeredAt));
        $user->recordThat(new UserEmailVerified($id, $registeredAt));

        return $user;
    }

    /**
     * Attach a provider identity to this existing account (ADR-0014).
     *
     * PRECONDITION, ENFORCED BY THE CALLER: the provider verified an address equal to this account's
     * email. Both sides then proved the same mailbox, which is what makes linking by email safe.
     *
     * - Linking an identity already held is a no-op with no event — idempotent, like `verifyEmail()`.
     * - A *different* identity from a provider already linked is refused (I-28).
     * - On an **unverified** account the password is discarded (I-29): see the comment at the line.
     *
     * Records `UserEmailVerified` (only if this verified the address) then `UserOAuthIdentityLinked`.
     */
    public function linkOAuthIdentity(OAuthIdentity $identity, \DateTimeImmutable $at): void
    {
        if ($this->hasOAuthIdentity($identity)) {
            return;
        }

        foreach ($this->oauthIdentities as $held) {
            if ($held->isFrom($identity->provider())) {
                throw OAuthProviderAlreadyLinked::forUser($this->id, $identity->provider());
            }
        }

        $passwordDiscarded = false;

        if (!$this->isEmailVerified()) {
            // I-29, THE PRE-HIJACK DEFENCE, AND DELIBERATELY NOT `changePassword()`. An unverified
            // account's password predates any proof of the mailbox: anyone could have registered this
            // address first and be waiting for its owner to sign in with Google. The owner has just
            // proved the mailbox, so a password nobody proved does not survive it.
            //
            // It is a plain assignment rather than `changePassword()` because nobody chose a new
            // password: there is no new hash, so `passwordChangedAt` stays as it was and
            // `UserPasswordChanged` is not recorded — that event would send a "your password was
            // changed" mail to someone who changed nothing. Any reset link still outstanding for this
            // address sits in the very mailbox that just proved itself, so I-23 needs nothing extra.
            $this->passwordHash = null;
            $passwordDiscarded = true;
        }

        $this->oauthIdentities[] = $identity;
        $this->verifyEmail($at);
        $this->recordThat(new UserOAuthIdentityLinked($this->id, $identity->provider(), $at, $passwordDiscarded));
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
     * OAuth identity"* — in executable form, enforced at login by `VerifiedAccountUserChecker`.
     *
     * THE PREDICTED SECOND CLAUSE WAS DECLINED (ADR-0014, AC-16). ADR-0005 expected
     * `identity-google-oauth` to widen this to `isEmailVerified() || hasVerifiedOAuthIdentity()`. It
     * did not, because that clause could never be the one that decides: an identity is only ever
     * linked on a provider-verified address that is also the account's own address, so
     * `registerWithOAuth()` sets `emailVerifiedAt` and `linkOAuthIdentity()` calls `verifyEmail()`.
     * Every account holding an identity is therefore already email-verified, and the extra clause
     * would be dead code with a security-sounding name. Should a future provider ever be linked
     * without verifying the email, *that* is when the clause earns its place — here, so no caller
     * changes.
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

    /**
     * `null` for a passwordless account: provisioned through a provider, or one whose unproven
     * password was discarded under I-29. Form login then fails exactly like a wrong password.
     */
    public function passwordHash(): ?HashedPassword
    {
        return $this->passwordHash;
    }

    public function hasPassword(): bool
    {
        return null !== $this->passwordHash;
    }

    /**
     * @return list<OAuthIdentity>
     */
    public function oauthIdentities(): array
    {
        return $this->oauthIdentities;
    }

    public function hasOAuthIdentity(OAuthIdentity $identity): bool
    {
        foreach ($this->oauthIdentities as $held) {
            if ($held->equals($identity)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `null` means "never changed since registration" (including "never had a password at all"),
     * which is a different statement from
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
