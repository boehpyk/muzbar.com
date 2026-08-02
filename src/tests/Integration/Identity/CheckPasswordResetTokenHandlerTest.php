<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Handler\CheckPasswordResetTokenHandler;
use App\Application\Identity\Query\CheckPasswordResetToken;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Exception\InvalidResetToken;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetLinkExpired;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Exception\StalePasswordResetRequest;
use App\Domain\Identity\Exception\UserNotFound;
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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `CheckPasswordResetTokenHandler` against the real `muzbar_test` database
 * (DAMA rollback). No spy dispatcher is wired here at all — the whole point of this handler is that
 * it mutates and dispatches nothing (AC-12), so its absence from the constructor call below is itself
 * part of what these tests pin.
 */
final class CheckPasswordResetTokenHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private PasswordResetRequestRepository $requests;
    private ResetTokenGenerator $tokens;
    private FrozenClock $clock;
    private CheckPasswordResetTokenHandler $handler;
    private Connection $connection;

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

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-29T09:00:00+00:00'));
        $this->handler = new CheckPasswordResetTokenHandler($this->users, $this->requests, $this->tokens, $this->clock);
    }

    /**
     * AC-12, the important one: the happy path returns (no exception) and the row is **byte-for-byte
     * unchanged** on re-read — every column, not merely `redeemed_at`/`invalidated_at`, and compared
     * after `$em->clear()` so the second read is a real hydration from Postgres rather than the same
     * in-memory row. This is the assertion that mail-scanner prefetch safety actually rests on: a
     * scanner fetching this link a thousand times must leave the row this test captured identical
     * every time.
     */
    public function testHappyPathReturnsAndTheRowIsByteForByteUnchangedOnReRead(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('check-live-link@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::createOne(['userId' => $user->id(), 'tokenHash' => $hash]);

        $before = $this->connection->fetchAssociative(
            'SELECT * FROM identity_password_reset_request WHERE token_hash = ?',
            [$hash->toString()],
        );
        self::assertIsArray($before);
        self::assertNull($before['redeemed_at']);
        self::assertNull($before['invalidated_at']);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));

        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $after = $this->connection->fetchAssociative(
            'SELECT * FROM identity_password_reset_request WHERE token_hash = ?',
            [$hash->toString()],
        );
        // `assertSame($before, $after)` already subsumes "redeemed_at and invalidated_at stayed
        // NULL" (they were asserted NULL on `$before` above) — a separate `assertNull($after[...])`
        // would be strictly weaker than the byte-for-byte comparison and PHPStan correctly flags it
        // as always-true once the array shapes are known to match.
        self::assertSame($before, $after, 'Every column of the request row must be unchanged after a mere check.');
    }

    /**
     * AC-17: a malformed token is refused **before any database query**. Asserted honestly rather
     * than by inference: the handler is built with a repository double whose every method throws
     * `\LogicException` the instant it is called, so if `ResetToken::fromString()` did not run first
     * and short-circuit, the test would fail with the wrong exception class (or an uncaught
     * `\LogicException`) rather than silently passing. That is what makes this a real assertion about
     * ordering rather than a hope about it.
     */
    public function testAMalformedTokenThrowsInvalidResetTokenBeforeAnyDatabaseQuery(): void
    {
        $repositoryThatMustNeverBeCalled = new class implements PasswordResetRequestRepository {
            public function nextIdentity(): PasswordResetRequestId
            {
                throw new \LogicException('nextIdentity() must not be called for a malformed token.');
            }

            public function save(PasswordResetRequest $request): void
            {
                throw new \LogicException('save() must not be called for a malformed token.');
            }

            public function findByTokenHash(HashedResetToken $hash): ?PasswordResetRequest
            {
                throw new \LogicException('findByTokenHash() must not be called for a malformed token.');
            }

            public function countIssuedForUserSince(UserId $userId, \DateTimeImmutable $since): int
            {
                throw new \LogicException('countIssuedForUserSince() must not be called for a malformed token.');
            }

            public function findOutstandingForUser(UserId $userId): array
            {
                throw new \LogicException('findOutstandingForUser() must not be called for a malformed token.');
            }

            // The two pruning methods `identity-challenge-pruning` added to the port. They belong to a
            // different use case entirely and this handler could not call them if it tried — but the
            // double implements the *whole* interface, so they throw like everything else here rather
            // than returning a polite `0`. A double that answers a question nobody should be asking it
            // is a double that cannot fail.
            public function countExpiredBefore(\DateTimeImmutable $threshold): int
            {
                throw new \LogicException('countExpiredBefore() must not be called for a malformed token.');
            }

            public function deleteExpiredBefore(\DateTimeImmutable $threshold, int $limit): int
            {
                throw new \LogicException('deleteExpiredBefore() must not be called for a malformed token.');
            }
        };

        $handler = new CheckPasswordResetTokenHandler($this->users, $repositoryThatMustNeverBeCalled, $this->tokens, $this->clock);

        $this->expectException(InvalidResetToken::class);

        $handler(new CheckPasswordResetToken('too-short'));
    }

    public function testAnUnknownTokenThrowsPasswordResetRequestNotFound(): void
    {
        $neverIssued = $this->tokens->generate();

        $this->expectException(PasswordResetRequestNotFound::class);

        ($this->handler)(new CheckPasswordResetToken($neverIssued->reveal()));
    }

    public function testAnExpiredTokenThrowsPasswordResetLinkExpired(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('check-expired@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        // Deliberately NOT the factory's `expired()` state, which measures "expired" against the
        // real wall clock — this handler's notion of "now" is the frozen clock, which has no fixed
        // relationship to the real wall clock the test happens to run on.
        PasswordResetRequestFactory::createOne([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $this->clock->now()->modify(\sprintf('-%d seconds', PasswordResetRequest::LIFETIME_SECONDS + 3600)),
        ]);

        $this->expectException(PasswordResetLinkExpired::class);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));
    }

    public function testAnAlreadyRedeemedTokenThrowsPasswordResetLinkAlreadyUsed(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('check-redeemed@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->redeemed($this->clock->now())->create();

        $this->expectException(PasswordResetLinkAlreadyUsed::class);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));
    }

    public function testAnInvalidatedTokenThrowsPasswordResetLinkInvalidated(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('check-invalidated@example.com')]);
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::new(['userId' => $user->id(), 'tokenHash' => $hash])->invalidated($this->clock->now())->create();

        $this->expectException(PasswordResetLinkInvalidated::class);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));
    }

    /**
     * I-23: a request issued strictly before the user's last password change is refused even though
     * its row otherwise looks perfectly live.
     */
    public function testAStaleTokenThrowsStalePasswordResetRequest(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('check-stale@example.com')]);
        $passwordChangedAt = $this->clock->now()->modify('+10 minutes');

        $foundUser = $this->users->findById($user->id());
        self::assertNotNull($foundUser);
        $foundUser->changePassword(HashedPassword::fromString('$2y$04$'.bin2hex(random_bytes(26))), $passwordChangedAt);
        $this->users->save($foundUser);

        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::createOne([
            'userId' => $user->id(),
            'tokenHash' => $hash,
            'issuedAt' => $this->clock->now(), // strictly before passwordChangedAt
        ]);

        $this->expectException(StalePasswordResetRequest::class);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));
    }

    /**
     * A request whose `user_id` matches no row — legitimate under ADR-0009 decision 4 (no foreign
     * key), and the only way this can happen honestly is a fresh, syntactically valid `UserId` with
     * no corresponding `identity_user` row, exactly what `PasswordResetRequestFactory`'s default
     * `userId` already is.
     */
    public function testARequestWhoseUserNoLongerExistsThrowsUserNotFound(): void
    {
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);
        PasswordResetRequestFactory::createOne([
            'userId' => UserId::fromString(Uuid::v7()->toRfc4122()),
            'tokenHash' => $hash,
        ]);

        $this->expectException(UserNotFound::class);

        ($this->handler)(new CheckPasswordResetToken($token->reveal()));
    }
}
