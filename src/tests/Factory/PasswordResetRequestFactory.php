<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `PasswordResetRequest` aggregates for tests through the one door the aggregate allows:
 * `PasswordResetRequest::issue()`. Same reasoning as `EmailVerificationRequestFactory` and
 * `UserFactory`: the aggregate has a private constructor and no setters, so Foundry's
 * reflection-based hydrator has nothing to set, and `instantiateWith()` replaces it with the same
 * named constructor `RequestPasswordResetHandler` calls. A factory that instead built the object by
 * reflection could create states the aggregate forbids — e.g. a redeemed-and-invalidated row, which
 * invariant I-17 says can never exist — and then the tests built on it would be testing a fiction
 * the aggregate itself would never produce.
 *
 * `issue()` records a `PasswordResetRequested` event on the aggregate, exactly as it would in
 * production. In production that event is released and dispatched by `RequestPasswordResetHandler`
 * right after `save()`; Foundry's persistence path bypasses the handler entirely (it persists the
 * object directly), so nothing would otherwise ever call `releaseEvents()` and the event would sit
 * in the buffer forever. Any test that later builds a fixture through this factory and then inspects
 * a spy dispatcher (e.g. `ResetPasswordWithTokenHandler` integration tests, which dispatch
 * `UserPasswordChanged`) would see this fixture's issuance event bleed into its own assertions —
 * "exactly one event dispatched" would see two, and the failure would land in a test that did
 * nothing wrong. `afterInstantiate()` below discards it, which is what makes a factory-built,
 * persisted `PasswordResetRequest` behave like any other already-persisted aggregate: no unpublished
 * history attached.
 *
 * @extends PersistentObjectFactory<PasswordResetRequest>
 */
final class PasswordResetRequestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PasswordResetRequest::class;
    }

    /**
     * @return array{id: PasswordResetRequestId, userId: UserId, tokenHash: HashedResetToken, issuedAt: \DateTimeImmutable}
     */
    protected function defaults(): array
    {
        return [
            'id' => PasswordResetRequestId::fromString(Uuid::v7()->toRfc4122()),

            // A fresh, syntactically valid UserId with no corresponding `identity_user` row —
            // legitimate here because the mapping deliberately holds no foreign key (ADR-0009
            // decision 4 / ADR-0011, `PasswordResetRequest.orm.xml`'s header comment): the
            // repository does not join to `identity_user`, so a request row never needs one to
            // exist. Tests that need the pair together (e.g. redemption flows) pass an explicit
            // `userId` from a real `UserFactory`-built user instead of relying on this default.
            'userId' => UserId::fromString(Uuid::v7()->toRfc4122()),

            // An opaque, arbitrary-shaped digest — not derived from any real `ResetToken`.
            // `HashedResetToken` deliberately performs no format validation (see its own test), so
            // any non-empty string under 255 characters is a valid fixture value. Tests that need to
            // *redeem* a request with a real plaintext build the pair through the
            // `ResetTokenGenerator` port themselves and pass the resulting hash here.
            'tokenHash' => HashedResetToken::fromString(hash('sha256', random_bytes(32))),

            'issuedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(static function (array $attributes): PasswordResetRequest {
                /** @var PasswordResetRequestId $id */
                $id = $attributes['id'];
                /** @var UserId $userId */
                $userId = $attributes['userId'];
                /** @var HashedResetToken $tokenHash */
                $tokenHash = $attributes['tokenHash'];
                /** @var \DateTimeImmutable $issuedAt */
                $issuedAt = $attributes['issuedAt'];

                return PasswordResetRequest::issue($id, $userId, $tokenHash, $issuedAt);
            })
            ->afterInstantiate(static function (PasswordResetRequest $request): void {
                $request->releaseEvents();
            })
        ;
    }

    /**
     * A request whose `issuedAt` is far enough in the past that it is already expired at the moment
     * of creation — `issuedAt + LIFETIME_SECONDS` (1 h) lies safely behind "now", well clear of the
     * boundary-instant edge case `PasswordResetRequestTest` pins exactly (that one needs a
     * `FrozenClock`, not a fixture; this state is for tests that only need "definitely expired").
     */
    public function expired(): static
    {
        return $this->with([
            'issuedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS + 3600)),
        ]);
    }

    /**
     * A request that has already been redeemed. Presents the aggregate's *own* stored hash back to
     * itself — the only way to satisfy `redeem()`'s constant-time comparison without knowing which
     * plaintext a fixture's opaque digest supposedly came from, since `HashedResetToken` carries no
     * such secret to begin with.
     *
     * `redeem()` records no event (see the aggregate's own docblock: the fact worth publishing is
     * `UserPasswordChanged`, which belongs to `User`), so there is nothing here for
     * `afterInstantiate` to release beyond what `initialize()` already discards.
     */
    public function redeemed(?\DateTimeImmutable $at = null): static
    {
        return $this->afterInstantiate(static function (PasswordResetRequest $request) use ($at): void {
            $request->redeem($request->tokenHash(), $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        });
    }

    /**
     * A request that has been superseded by a (fictional, for this fixture's purposes) newer one —
     * the state `RequestPasswordResetHandler`'s reissue sweep produces via `invalidate()`.
     *
     * Like `redeemed()`, `invalidate()` records no event (AC-34 — invalidation is bookkeeping the
     * system does to itself, not a fact a domain expert would name), so there is nothing here for
     * `afterInstantiate` to release either.
     */
    public function invalidated(?\DateTimeImmutable $at = null): static
    {
        return $this->afterInstantiate(static function (PasswordResetRequest $request) use ($at): void {
            $request->invalidate($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        });
    }

    /**
     * Overrides `issuedAt` directly, for tests that need a specific instant rather than merely
     * "expired" or "live" — e.g. pinning the stale-request guard (I-23), which compares a request's
     * `issuedAt` against a user's `passwordChangedAt`. `expiresAt` remains derived (invariant I-15):
     * this state cannot and does not set it independently, since only `issue()` may derive it.
     */
    public function issuedAt(\DateTimeImmutable $issuedAt): static
    {
        return $this->with(['issuedAt' => $issuedAt]);
    }
}
