<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\ResetPasswordWithToken;
use App\Application\Identity\Handler\ResetPasswordWithTokenHandler;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Event\UserPasswordChanged;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetLinkExpired;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Exception\PasswordResetTokenMismatch;
use App\Domain\Identity\Exception\StalePasswordResetRequest;
use App\Domain\Identity\Exception\WeakPassword;
use App\Domain\Identity\Port\PasswordHasher;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\HashedPassword;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\PasswordResetRequestId;
use App\Domain\Identity\ValueObject\UserId;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `ResetPasswordWithTokenHandler` against the real `muzbar_test` database (DAMA
 * rollback), with a frozen `Clock` and a spy `DomainEventDispatcher`. Mirrors
 * `VerifyEmailWithTokenHandlerTest`'s shape while pinning every place the two handlers deliberately
 * diverge — replay refused rather than absorbed, reset-implies-verified, and the stale-request guard.
 */
final class ResetPasswordWithTokenHandlerTest extends KernelTestCase
{
    private const string A_STRONG_NEW_PASSWORD = 'a-perfectly-strong-new-password';

    private UserRepository $users;
    private PasswordResetRequestRepository $requests;
    private ResetTokenGenerator $tokens;
    private PasswordHasher $hasher;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private ResetPasswordWithTokenHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);
        $this->requests = $requests;

        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $this->tokens = $tokens;

        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->hasher = $hasher;

        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-29T09:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();
        $this->handler = new ResetPasswordWithTokenHandler($this->users, $this->requests, $this->tokens, $this->hasher, $this->clock, $this->events);
    }

    /**
     * AC-20, AC-30: the happy path (against an already-verified account) changes the stored hash,
     * sets `passwordChangedAt`, marks the request redeemed, and dispatches exactly one
     * `UserPasswordChanged` — `verifyEmail()`'s idempotency on an already-verified account is what
     * keeps this to one event rather than two (AC-30).
     */
    public function testHappyPathChangesTheHashSetsPasswordChangedAtRedeemsTheRequestAndDispatchesOneEvent(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('reset-verified@example.com')])->verified()->create();
        $originalHash = $user->passwordHash()->toString();
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        self::assertNotSame($originalHash, $foundUser->passwordHash()->toString());
        self::assertEquals(new \DateTimeImmutable('2026-07-29T09:00:00+00:00'), $foundUser->passwordChangedAt());

        $foundRequest = $this->requests->findByTokenHash($hash);
        self::assertNotNull($foundRequest);
        self::assertTrue($foundRequest->isRedeemed());
        self::assertEquals(new \DateTimeImmutable('2026-07-29T09:00:00+00:00'), $foundRequest->redeemedAt());

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserPasswordChanged::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($user->id()));
    }

    /**
     * AC-28, AC-29: resetting the password of an **unverified** account also verifies its email —
     * asserted here at the aggregate/repository level (the accompanying Functional test asserts it
     * through an actual `/login` POST, per AC-28's own wording) — and dispatches **both**
     * `UserPasswordChanged` and `UserEmailVerified`, exactly once each.
     */
    public function testAnUnverifiedUserIsAlsoVerifiedAndTwoEventsAreDispatched(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-unverified@example.com')]);
        self::assertFalse($user->isEmailVerified());
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        self::assertTrue($foundUser->isEmailVerified());
        self::assertEquals(new \DateTimeImmutable('2026-07-29T09:00:00+00:00'), $foundUser->emailVerifiedAt());

        $dispatched = $this->events->dispatched();
        self::assertCount(2, $dispatched);
        self::assertInstanceOf(UserPasswordChanged::class, $dispatched[0]);
        self::assertInstanceOf(UserEmailVerified::class, $dispatched[1]);
        self::assertTrue($dispatched[1]->userId()->equals($user->id()));
    }

    public function testAnExpiredTokenThrowsPasswordResetLinkExpiredAndLeavesThePasswordUnchanged(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-expired@example.com')]);
        $originalHash = $user->passwordHash()->toString();
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $this->clock->now()->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS + 3600)),
        ])->create();

        $this->expectException(PasswordResetLinkExpired::class);

        try {
            ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));
        } finally {
            $foundUser = $this->users->findById($user->id());
            self::assertNotNull($foundUser);
            self::assertSame($originalHash, $foundUser->passwordHash()->toString());
            self::assertSame([], $this->events->dispatched());
        }
    }

    public function testAnUnknownTokenThrowsPasswordResetRequestNotFound(): void
    {
        $neverIssued = $this->tokens->generate();

        $this->expectException(PasswordResetRequestNotFound::class);

        try {
            ($this->handler)(new ResetPasswordWithToken($neverIssued->reveal(), self::A_STRONG_NEW_PASSWORD));
        } finally {
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * `PasswordResetTokenMismatch` is close to unreachable through the real repository, because
     * `findByTokenHash()` finds a request *by* the exact digest the handler then presents back to
     * `assertRedeemableWith()`. This test simulates a repository that misbehaves — returning a
     * request whose own stored hash does not match what was searched for — the same technique
     * `VerifyEmailWithTokenHandlerTest` uses for the same reason.
     */
    public function testAMismatchedRepositoryResultThrowsPasswordResetTokenMismatch(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-mismatched@example.com')]);
        $storedHash = HashedResetToken::fromString('the-digest-actually-on-the-row');
        $misbehavingRequest = PasswordResetRequest::issue(
            PasswordResetRequestId::fromString(Uuid::v7()->toRfc4122()),
            $user->id(),
            $storedHash,
            $this->clock->now(),
        );
        $misbehavingRequest->releaseEvents();

        $fakeRequests = new class($misbehavingRequest) implements PasswordResetRequestRepository {
            public function __construct(private readonly PasswordResetRequest $alwaysReturns)
            {
            }

            public function nextIdentity(): PasswordResetRequestId
            {
                return PasswordResetRequestId::fromString(Uuid::v7()->toRfc4122());
            }

            public function save(PasswordResetRequest $request): void
            {
                // Never reached: `assertRedeemableWith()` throws before either `save()` call.
            }

            public function findByTokenHash(HashedResetToken $hash): PasswordResetRequest
            {
                // Deliberately ignores $hash — simulating a repository handing back a row it was
                // not asked for.
                return $this->alwaysReturns;
            }

            public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
            {
                return 0;
            }

            public function findOutstandingForUser(UserId $userId): array
            {
                return [];
            }
        };

        $handler = new ResetPasswordWithTokenHandler($this->users, $fakeRequests, $this->tokens, $this->hasher, $this->clock, $this->events);
        $presentedToken = $this->tokens->generate(); // hashes to something other than $storedHash

        $this->expectException(PasswordResetTokenMismatch::class);

        try {
            $handler(new ResetPasswordWithToken($presentedToken->reveal(), self::A_STRONG_NEW_PASSWORD));
        } finally {
            self::assertSame([], $this->events->dispatched());
        }
    }

    public function testAnInvalidatedTokenThrowsPasswordResetLinkInvalidated(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-invalidated@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->invalidated($this->clock->now())->create();

        $this->expectException(PasswordResetLinkInvalidated::class);

        try {
            ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));
        } finally {
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * AC-18: replay is refused, not absorbed. After a successful reset, presenting the *same* token
     * again throws `PasswordResetLinkAlreadyUsed` and does not change the password a second time —
     * the sharpest single contrast with `VerifyEmailWithTokenHandlerTest`'s friendly `AlreadyVerified`
     * branch.
     */
    public function testReplayingTheSameTokenAfterASuccessfulResetThrowsPasswordResetLinkAlreadyUsed(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-replay@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));
        $hashAfterFirstReset = $this->users->findById($user->id())?->passwordHash()->toString();
        self::assertNotNull($hashAfterFirstReset);

        $laterClock = new FrozenClock(new \DateTimeImmutable('2026-07-29T09:30:00+00:00'));
        $laterEvents = new SpyDomainEventDispatcher();
        $laterHandler = new ResetPasswordWithTokenHandler($this->users, $this->requests, $this->tokens, $this->hasher, $laterClock, $laterEvents);

        $this->expectException(PasswordResetLinkAlreadyUsed::class);

        try {
            $laterHandler(new ResetPasswordWithToken($token->reveal(), 'a-completely-different-password'));
        } finally {
            self::assertSame($hashAfterFirstReset, $this->users->findById($user->id())?->passwordHash()->toString(), 'A replay must not change the password a second time.');
            self::assertSame([], $laterEvents->dispatched());
        }
    }

    /**
     * AC-23 at the handler level: a weak new password throws `WeakPassword` and — the assertion that
     * pins the ordering — leaves `redeemed_at` `NULL`. The password is validated *after* the token
     * checks and *before* `redeem()`, so a bad password submitted against a good link must not burn
     * that link.
     */
    public function testAWeakPasswordThrowsWeakPasswordAndLeavesRedeemedAtNull(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-weak-password@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->create();

        $this->expectException(WeakPassword::class);

        try {
            ($this->handler)(new ResetPasswordWithToken($token->reveal(), 'too-short'));
        } finally {
            $foundRequest = $this->requests->findByTokenHash($hash);
            self::assertNotNull($foundRequest);
            self::assertNull($foundRequest->redeemedAt());
            self::assertFalse($foundRequest->isRedeemed());
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * AC-26, I-23: a request issued strictly before the user's last password change is refused even
     * though its own row otherwise looks perfectly live — the second layer that closes the crash
     * window between the reissue sweep and a lost invalidation write.
     */
    public function testARequestIssuedBeforeThePasswordWasLastChangedThrowsStalePasswordResetRequest(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-stale@example.com')]);
        $passwordChangedAt = $this->clock->now()->modify('-5 minutes');

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        $foundUser->changePassword(HashedPassword::fromString('$2y$04$'.bin2hex(random_bytes(26))), $passwordChangedAt);
        $this->users->save($foundUser);

        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $passwordChangedAt->modify('-1 second'), // strictly before
        ])->create();

        $this->expectException(StalePasswordResetRequest::class);

        try {
            ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));
        } finally {
            $foundRequest = $this->requests->findByTokenHash($hash);
            self::assertNotNull($foundRequest);
            self::assertNull($foundRequest->redeemedAt());
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * I-23's strict `<`: a request issued in the **same second** as the user's `passwordChangedAt` is
     * allowed through rather than refused. Whole-second storage makes ties real, and erring toward
     * the legitimate user is deliberate (I-23's own docblock).
     */
    public function testARequestIssuedInTheSameSecondAsThePasswordChangeIsAllowedThroughNotRefused(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reset-tie@example.com')]);
        $passwordChangedAt = $this->clock->now()->modify('-5 minutes');

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        $foundUser->changePassword(HashedPassword::fromString('$2y$04$'.bin2hex(random_bytes(26))), $passwordChangedAt);
        $this->users->save($foundUser);

        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $passwordChangedAt, // exact tie, not strictly before
        ])->create();

        ($this->handler)(new ResetPasswordWithToken($token->reveal(), self::A_STRONG_NEW_PASSWORD));

        $foundRequest = $this->requests->findByTokenHash($hash);
        self::assertNotNull($foundRequest);
        self::assertTrue($foundRequest->isRedeemed(), 'A same-second tie must be allowed through, not refused as stale.');
    }
}
