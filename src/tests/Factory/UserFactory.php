<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\OAuthIdentity;
use App\Domain\Identity\ValueObject\OAuthProvider;
use App\Domain\Identity\ValueObject\Role;
use App\Domain\Identity\ValueObject\UserId;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `User` aggregates for tests through the one door the aggregate allows:
 * `User::register()`. `User` has a private constructor and no setters — Foundry's default
 * reflection-based hydrator would have nothing to set — so this factory replaces it entirely
 * with `instantiateWith()`, which calls the same named constructor `RegisterUserHandler` calls.
 *
 * Every functional and integration test in this slice depends on this factory (technical plan,
 * Test plan section), which is why it is T33 and written first.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    /**
     * @return array{id: UserId, email: Email, passwordHash: HashedPassword, registeredAt: \DateTimeImmutable}
     */
    protected function defaults(): array
    {
        return [
            'id' => UserId::fromString(Uuid::v7()->toRfc4122()),
            'email' => Email::fromString(self::faker()->unique()->safeEmail()),
            // An opaque, arbitrary-shaped value — not a real bcrypt/argon2 hash. `HashedPassword`
            // deliberately does not validate the algorithm's shape (see HashedPasswordTest), and
            // fixtures that need a hash a *real* login can verify against should build one via the
            // `PasswordHasher` port instead of relying on this default (see the Functional login
            // tests, which do exactly that).
            'passwordHash' => HashedPassword::fromString('$2y$04$'.bin2hex(random_bytes(26))),
            'registeredAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(static function (array $attributes): User {
                /** @var UserId $id */
                $id = $attributes['id'];
                /** @var Email $email */
                $email = $attributes['email'];
                /** @var HashedPassword $passwordHash */
                $passwordHash = $attributes['passwordHash'];
                /** @var \DateTimeImmutable $registeredAt */
                $registeredAt = $attributes['registeredAt'];

                return User::register($id, $email, $passwordHash, $registeredAt);
            })
            // `User::register()` above records a `UserRegistered` event on the aggregate, exactly
            // as it would in production. In production that event is released and dispatched by
            // `RegisterUserHandler` right after `save()`; Foundry's persistence path bypasses the
            // handler entirely (it persists the object directly), so nothing ever calls
            // `releaseEvents()` and the event would otherwise sit in the buffer forever. Any test
            // that later mutates a factory-built user (e.g. `verifyEmail()`) and inspects a spy
            // dispatcher would then see this fixture's registration event bleed into its own
            // assertions. Releasing it here makes a factory-built, persisted `User` behave like
            // any other already-persisted aggregate: no unpublished history attached.
            ->afterInstantiate(static function (User $user): void {
                $user->releaseEvents();
            })
        ;
    }

    /**
     * A registered-and-already-verified user, for tests that need `isUsable() === true` or that
     * simply do not care about verification state.
     *
     * The `UserEmailVerified` event this raises is deliberately discarded (`releaseEvents()`
     * below): this is fixture setup, not the use case under test, and a factory that leaks events
     * into a spy dispatcher would let one test's background fixture pollute another test's event
     * assertions.
     */
    public function verified(?\DateTimeImmutable $at = null): static
    {
        return $this->afterInstantiate(static function (User $user) use ($at): void {
            $user->verifyEmail($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $user->releaseEvents();
        });
    }

    /**
     * Holds `ROLE_ADMIN`, through `User::grantRole()` as the console command does. Grant it
     * *before* the user signs in: changing a signed-in user's roles logs them out on their next
     * request (Symfony's user refresh).
     */
    public function admin(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->grantRole(Role::Admin);
        });
    }

    /**
     * A user provisioned the way "Continue with Google" creates one: through
     * `User::registerWithOAuth()` — passwordless, email-verified, one linked identity. The
     * factory's `passwordHash` default is ignored. Replaces the `register()` instantiator, so
     * the `afterInstantiate` event release from `initialize()` still applies.
     */
    public function provisionedViaGoogle(string $subject = 'google-sub-fixture'): static
    {
        return $this->instantiateWith(static function (array $attributes) use ($subject): User {
            /** @var UserId $id */
            $id = $attributes['id'];
            /** @var Email $email */
            $email = $attributes['email'];
            /** @var \DateTimeImmutable $registeredAt */
            $registeredAt = $attributes['registeredAt'];

            return User::registerWithOAuth($id, $email, OAuthIdentity::of(OAuthProvider::Google, $subject), $registeredAt);
        });
    }

    /**
     * Links a Google identity through `User::linkOAuthIdentity()` after the password registration,
     * so I-29 applies as in production: on an unverified user this discards the password. Call it
     * after `verified()` to keep the password.
     */
    public function linkedToGoogle(string $subject = 'google-sub-fixture'): static
    {
        return $this->afterInstantiate(static function (User $user) use ($subject): void {
            $user->linkOAuthIdentity(
                OAuthIdentity::of(OAuthProvider::Google, $subject),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
            $user->releaseEvents();
        });
    }
}
