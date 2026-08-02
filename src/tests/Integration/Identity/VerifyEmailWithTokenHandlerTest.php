<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\VerifyEmailWithToken;
use App\Application\Identity\Handler\VerifyEmailWithTokenHandler;
use App\Application\Identity\VerificationOutcome;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Event\UserEmailVerified;
use App\Domain\Identity\Exception\EmailVerificationLinkExpired;
use App\Domain\Identity\Exception\EmailVerificationRequestNotFound;
use App\Domain\Identity\Exception\EmailVerificationTokenMismatch;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\EmailVerificationRequestId;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Tests\Factory\EmailVerificationRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `VerifyEmailWithTokenHandler` against the real `muzbar_test` database (DAMA
 * rollback), with a frozen `Clock` and a spy `DomainEventDispatcher`.
 */
final class VerifyEmailWithTokenHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private EmailVerificationRequestRepository $requests;
    private VerificationTokenGenerator $tokens;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private VerifyEmailWithTokenHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $requests = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $requests);
        $this->requests = $requests;

        $tokens = self::getContainer()->get(VerificationTokenGenerator::class);
        self::assertInstanceOf(VerificationTokenGenerator::class, $tokens);
        $this->tokens = $tokens;

        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-26T09:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();
        $this->handler = new VerifyEmailWithTokenHandler($this->users, $this->requests, $this->tokens, $this->clock, $this->events);
    }

    /**
     * AC-7, AC-27: the happy path verifies the user, marks the request redeemed, dispatches exactly
     * one `UserEmailVerified` carrying this user's id, and returns `VerificationOutcome::Verified`.
     */
    public function testHappyPathVerifiesTheUserMarksTheRequestRedeemedAndDispatchesOneEvent(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('to-verify-by-link@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        EmailVerificationRequestFactory::createOne(['userId' => $user->id(), 'tokenHash' => $hash]);

        $outcome = ($this->handler)(new VerifyEmailWithToken($token->reveal()));

        self::assertSame(VerificationOutcome::Verified, $outcome);

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        self::assertTrue($foundUser->isEmailVerified());
        self::assertEquals(new \DateTimeImmutable('2026-07-26T09:00:00+00:00'), $foundUser->emailVerifiedAt());

        $foundRequest = $this->requests->findByTokenHash($hash);
        self::assertNotNull($foundRequest);
        self::assertTrue($foundRequest->isRedeemed());
        self::assertEquals(new \DateTimeImmutable('2026-07-26T09:00:00+00:00'), $foundRequest->redeemedAt());

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserEmailVerified::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($user->id()));
    }

    /**
     * AC-8: replaying the same token after a successful redemption returns `AlreadyVerified`,
     * mutates nothing further (the timestamp does not move even though the clock has advanced) and
     * dispatches no event at all — the short-circuit runs *before* `redeem()`.
     */
    public function testReplayingTheSameTokenReturnsAlreadyVerifiedMutatesNothingAndDispatchesNothing(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('replayed-link@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        EmailVerificationRequestFactory::createOne(['userId' => $user->id(), 'tokenHash' => $hash]);

        ($this->handler)(new VerifyEmailWithToken($token->reveal()));
        $firstVerifiedAt = $this->users->findById($user->id())?->emailVerifiedAt();
        self::assertNotNull($firstVerifiedAt);
        $firstRedeemedAt = $this->requests->findByTokenHash($hash)?->redeemedAt();
        self::assertNotNull($firstRedeemedAt);

        $laterClock = new FrozenClock(new \DateTimeImmutable('2026-08-01T00:00:00+00:00'));
        $secondEvents = new SpyDomainEventDispatcher();
        $handler = new VerifyEmailWithTokenHandler($this->users, $this->requests, $this->tokens, $laterClock, $secondEvents);

        $outcome = $handler(new VerifyEmailWithToken($token->reveal()));

        self::assertSame(VerificationOutcome::AlreadyVerified, $outcome);
        self::assertEquals($firstVerifiedAt, $this->users->findById($user->id())?->emailVerifiedAt());
        self::assertEquals($firstRedeemedAt, $this->requests->findByTokenHash($hash)?->redeemedAt());
        self::assertSame([], $secondEvents->dispatched());
    }

    public function testAnExpiredLinkThrowsEmailVerificationLinkExpired(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('expired-link@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        // Deliberately NOT the factory's `expired()` state, which measures "expired" against the
        // real wall clock. This handler's notion of "now" is the frozen clock in `$this->clock`
        // (2026-07-26T09:00:00+00:00), which has no fixed relationship to the real wall clock the
        // test happens to run on — so "expired relative to `expired()`'s real now" is not the same
        // fact as "expired relative to *this test's* now". Issuing the request a day-plus-an-hour
        // before the frozen clock's instant is what makes it expired according to the clock the
        // handler under test actually reads.
        EmailVerificationRequestFactory::createOne([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $this->clock->now()->modify(\sprintf('-%d seconds', EmailVerificationRequest::LIFETIME_SECONDS + 3600)),
        ]);

        $this->expectException(EmailVerificationLinkExpired::class);

        try {
            ($this->handler)(new VerifyEmailWithToken($token->reveal()));
        } finally {
            $foundUser = $this->users->findById($user->id());
            self::assertNotNull($foundUser);
            self::assertFalse($foundUser->isEmailVerified());
            self::assertSame([], $this->events->dispatched());
        }
    }

    public function testAnUnknownTokenThrowsEmailVerificationRequestNotFound(): void
    {
        $neverIssuedToken = $this->tokens->generate();

        $this->expectException(EmailVerificationRequestNotFound::class);

        try {
            ($this->handler)(new VerifyEmailWithToken($neverIssuedToken->reveal()));
        } finally {
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * `EmailVerificationTokenMismatch` is close to unreachable through the real repository, because
     * `findByTokenHash()` finds a request *by* the exact digest the handler then presents back to
     * `redeem()` — see the aggregate's own docblock. It is nonetheless the handler's job to propagate
     * the mismatch faithfully if a repository ever *did* misbehave (a broken DBAL type, a broken
     * query), so this test simulates exactly that with a repository double that returns a request
     * whose stored hash deliberately does not match what was searched for.
     */
    public function testAMismatchedRepositoryResultThrowsEmailVerificationTokenMismatch(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('mismatched-repo@example.com')]);
        $storedHash = HashedVerificationToken::fromString('the-digest-actually-on-the-row');
        $misbehavingRequest = EmailVerificationRequest::issue(
            EmailVerificationRequestId::fromString(Uuid::v7()->toRfc4122()),
            $user->id(),
            $storedHash,
            new \DateTimeImmutable('2026-07-26T08:00:00+00:00'),
        );
        $misbehavingRequest->releaseEvents();

        $fakeRequests = new class($misbehavingRequest) implements EmailVerificationRequestRepository {
            public function __construct(private readonly EmailVerificationRequest $alwaysReturns)
            {
            }

            public function nextIdentity(): EmailVerificationRequestId
            {
                return EmailVerificationRequestId::fromString(Uuid::v7()->toRfc4122());
            }

            public function save(EmailVerificationRequest $request): void
            {
                // Never reached: `redeem()` throws before the handler's second `save()` call.
            }

            // Narrower than the port's `?EmailVerificationRequest` — a covariant return type PHP
            // permits on an implementing class — because this double always has an answer to give;
            // PHPStan (correctly) flags a nullable return type that no code path in this
            // implementation ever produces.
            public function findByTokenHash(HashedVerificationToken $hash): EmailVerificationRequest
            {
                // Deliberately ignores $hash and always returns the fixed request, whose own
                // tokenHash differs from whatever was searched for — simulating a repository
                // handing back a row it was not asked for.
                return $this->alwaysReturns;
            }

            public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
            {
                return 0;
            }

            // The pruning pair `identity-challenge-pruning` added to the port. This double's whole
            // job is to hand back the wrong row on a lookup; the sweep is a different use case and
            // must never be reached from a redemption, so these throw rather than returning a
            // harmless 0 that would let such a call pass unnoticed.
            public function countExpiredBefore(\DateTimeImmutable $threshold): int
            {
                throw new \LogicException('countExpiredBefore() has no business in a verification.');
            }

            public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
            {
                throw new \LogicException('deleteExpiredBefore() has no business in a verification.');
            }
        };

        $handler = new VerifyEmailWithTokenHandler($this->users, $fakeRequests, $this->tokens, $this->clock, $this->events);
        $presentedToken = $this->tokens->generate(); // hashes to something other than $storedHash

        $this->expectException(EmailVerificationTokenMismatch::class);

        try {
            $handler(new VerifyEmailWithToken($presentedToken->reveal()));
        } finally {
            $foundUser = $this->users->findById($user->id());
            self::assertNotNull($foundUser);
            self::assertFalse($foundUser->isEmailVerified());
            self::assertSame([], $this->events->dispatched());
        }
    }
}
