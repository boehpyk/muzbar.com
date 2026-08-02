<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds `EmailVerificationRequest` aggregates for tests through the one door the aggregate
 * allows: `EmailVerificationRequest::issue()`. Same reasoning as `UserFactory`: the aggregate has a
 * private constructor and no setters, so Foundry's reflection-based hydrator has nothing to set,
 * and `instantiateWith()` replaces it with the same named constructor
 * `RequestEmailVerificationHandler` calls.
 *
 * `issue()` records an `EmailVerificationRequested` event on the aggregate, exactly as it would in
 * production. In production that event is released and dispatched by
 * `RequestEmailVerificationHandler` right after `save()`; Foundry's persistence path bypasses the
 * handler entirely (it persists the object directly), so nothing would otherwise ever call
 * `releaseEvents()` and the event would sit in the buffer forever. Any test that later builds a
 * fixture through this factory and then inspects a spy dispatcher (e.g. `VerifyEmailWithTokenHandler`
 * integration tests, which dispatch `UserEmailVerified`) would see this fixture's issuance event
 * bleed into its own assertions. `afterInstantiate()` below discards it, which is what makes a
 * factory-built, persisted `EmailVerificationRequest` behave like any other already-persisted
 * aggregate: no unpublished history attached.
 *
 * @extends PersistentObjectFactory<EmailVerificationRequest>
 */
final class EmailVerificationRequestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return EmailVerificationRequest::class;
    }

    /**
     * @return array{id: EmailVerificationRequestId, userId: UserId, tokenHash: HashedVerificationToken, issuedAt: \DateTimeImmutable}
     */
    protected function defaults(): array
    {
        return [
            'id' => EmailVerificationRequestId::fromString(Uuid::v7()->toRfc4122()),

            // A fresh, syntactically valid UserId with no corresponding `identity_user` row —
            // legitimate here because the mapping deliberately holds no foreign key (ADR-0009
            // decision 4, `EmailVerificationRequest.orm.xml`'s header comment): the repository does
            // not join to `identity_user`, so a request row never needs one to exist. Tests that
            // need the pair together (e.g. redemption flows) pass an explicit `userId` from a real
            // `UserFactory`-built user instead of relying on this default.
            'userId' => UserId::fromString(Uuid::v7()->toRfc4122()),

            // An opaque, arbitrary-shaped digest — not derived from any real `VerificationToken`.
            // `HashedVerificationToken` deliberately performs no format validation (see its own
            // test), so any non-empty string under 255 characters is a valid fixture value. Tests
            // that need to *redeem* a request with a real plaintext build the pair through the
            // `VerificationTokenGenerator` port themselves and pass the resulting hash here.
            'tokenHash' => HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),

            'issuedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(static function (array $attributes): EmailVerificationRequest {
                /** @var EmailVerificationRequestId $id */
                $id = $attributes['id'];
                /** @var UserId $userId */
                $userId = $attributes['userId'];
                /** @var HashedVerificationToken $tokenHash */
                $tokenHash = $attributes['tokenHash'];
                /** @var \DateTimeImmutable $issuedAt */
                $issuedAt = $attributes['issuedAt'];

                return EmailVerificationRequest::issue($id, $userId, $tokenHash, $issuedAt);
            })
            ->afterInstantiate(static function (EmailVerificationRequest $request): void {
                $request->releaseEvents();
            })
        ;
    }

    /**
     * A request whose `issuedAt` is far enough in the past that it is already expired at the moment
     * of creation — `issuedAt + LIFETIME_SECONDS` (24 h) lies safely behind "now", well clear of the
     * boundary-instant edge case `EmailVerificationRequestTest` pins exactly (that one needs a
     * `FrozenClock`, not a fixture; this state is for tests that only need "definitely expired").
     */
    public function expired(): static
    {
        return $this->with([
            'issuedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS + 3600)),
        ]);
    }

    /**
     * A request old enough that the pruning sweep would take it: `expiresAt` lies strictly before
     * `EmailVerificationRequest::retentionThreshold(now)`.
     *
     * NOTE WHAT THIS STATE DOES **NOT** DO, BECAUSE IT IS THE WHOLE REASON IT LOOKS INDIRECT. It does
     * not set `expiresAt`. It cannot: `expiresAt` is derived inside `issue()` and is not a parameter
     * (invariant I-8), and this factory goes through `issue()` via `instantiateWith()` exactly as
     * every other state here does. So the only lever is `issuedAt`, and the arithmetic runs
     * *backwards* from the property the test actually wants — a row is overdue when
     * `issuedAt + LIFETIME_SECONDS < now - RETENTION_AFTER_EXPIRY_SECONDS`, hence an `issuedAt` of
     * `now - LIFETIME - RETENTION - slack`.
     *
     * A factory that reached around the named constructor to write an arbitrary `expiresAt` would be
     * quicker and would be fabricating a row the aggregate could never produce — and the tests built
     * on it would then be testing a fiction. Where a genuinely impossible row *is* required (a corrupt
     * digest, an `expiresAt` that disagrees with the current lifetime), the test writes it with raw
     * SQL and says so, which is honest about being outside the model rather than quietly widening the
     * factory to permit impossibilities.
     *
     * The slack is a day rather than a second: this state is for tests that need "definitely
     * overdue". The boundary itself — `threshold − 1 s` deleted, `threshold` and `threshold + 1 s`
     * kept — is pinned exactly by `DoctrineEmailVerificationRequestRepositoryTest`, which builds its
     * rows from an explicit instant instead, because a boundary assertion that leaned on a fixture's
     * idea of "long ago" would not be a boundary assertion.
     */
    public function expiredLongAgo(): static
    {
        return $this->with([
            'issuedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(\sprintf(
                    '-%d seconds',
                    EmailVerificationRequest::LIFETIME_SECONDS
                        + EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS
                        + 86400,
                )),
        ]);
    }

    /**
     * A request that has already been redeemed. Presents the aggregate's *own* stored hash back to
     * itself — the only way to satisfy `redeem()`'s constant-time comparison without knowing which
     * plaintext a fixture's opaque digest supposedly came from, since `HashedVerificationToken`
     * carries no such secret to begin with.
     *
     * `redeem()` records no event (see the aggregate's own docblock: the fact worth publishing is
     * `UserEmailVerified`, which belongs to `User`), so there is nothing here for `afterInstantiate`
     * to release beyond what `initialize()` already discards.
     */
    public function redeemed(?\DateTimeImmutable $at = null): static
    {
        return $this->afterInstantiate(static function (EmailVerificationRequest $request) use ($at): void {
            $request->redeem($request->tokenHash(), $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        });
    }
}
