<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
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
}
