<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\RequestEmailVerification;
use App\Application\Identity\Handler\RequestEmailVerificationHandler;
use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Event\EmailVerificationRequested;
use App\Domain\Identity\Exception\EmailAlreadyVerified;
use App\Domain\Identity\Exception\TooManyVerificationRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\Port\VerificationTokenGenerator;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\RecordingVerificationMailer;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `RequestEmailVerificationHandler` against the real `muzbar_test` database
 * (DAMA rollback), with a frozen `Clock`, a spy `DomainEventDispatcher` and a recording
 * `VerificationMailer` double standing in for the real ones — the technical plan's Test plan
 * prescription exactly.
 */
final class RequestEmailVerificationHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private EmailVerificationRequestRepository $requests;
    private VerificationTokenGenerator $tokens;
    private RecordingVerificationMailer $mailer;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private RequestEmailVerificationHandler $handler;
    private Connection $connection;

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

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->mailer = new RecordingVerificationMailer();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-26T12:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();

        $this->handler = new RequestEmailVerificationHandler($this->users, $this->requests, $this->tokens, $this->mailer, $this->clock, $this->events);
    }

    /**
     * AC-1, AC-3, AC-26: the happy path persists exactly one request row for the user, sends exactly
     * one message through the mailer port, and dispatches exactly one `EmailVerificationRequested`
     * carrying the persisted request's own id, this user's id, and the same `issuedAt`/`expiresAt`
     * the row holds.
     */
    public function testHappyPathPersistsOneRequestSendsOneMailAndDispatchesOneEvent(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('needs-verifying@example.com')]);

        ($this->handler)(new RequestEmailVerification('needs-verifying@example.com'));

        self::assertSame(1, $this->requestRowCountFor($user->id()->toString()));

        self::assertSame(1, $this->mailer->count());
        self::assertTrue($this->mailer->lastRecipient()->equals(Email::fromString('needs-verifying@example.com')));
        self::assertSame(43, \strlen($this->mailer->lastToken()->reveal()));
        self::assertEquals(new \DateTimeImmutable('2026-07-27T12:00:00+00:00'), $this->mailer->lastExpiresAt());

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(EmailVerificationRequested::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($user->id()));
        self::assertEquals(new \DateTimeImmutable('2026-07-26T12:00:00+00:00'), $dispatched[0]->issuedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-27T12:00:00+00:00'), $dispatched[0]->expiresAt());
    }

    /**
     * AC-1: the persisted row's own column values, not merely its count. `token_hash` must be a
     * 64-character lower-case hex string (`RandomVerificationTokenGenerator`'s SHA-256, unsalted
     * and deterministic — see its docblock for why that is safe here), `issued_at` must be exactly
     * the frozen `Clock`'s instant, `expires_at` must be `issued_at` plus 24 hours to the second
     * (`EmailVerificationRequest::LIFETIME_SECONDS`), and `redeemed_at` must be `NULL` because
     * nothing has redeemed this request yet. The 24h *derivation* is already covered at the
     * aggregate's own unit level and the Clock wiring is already covered by
     * `testIssuedAtComesFromTheClockPortNotTheWallClock` below — but AC-1 is explicitly about the
     * row a `SELECT` returns, and until this test existed nothing read the row back and checked it.
     */
    public function testHappyPathRequestRowColumnsMatchAC1(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('ac1-columns@example.com')]);

        ($this->handler)(new RequestEmailVerification('ac1-columns@example.com'));

        $row = $this->connection->fetchAssociative(
            'SELECT token_hash, issued_at, expires_at, redeemed_at FROM identity_email_verification_request WHERE user_id = ?',
            [$user->id()->toString()],
        );
        self::assertIsArray($row);

        self::assertIsString($row['token_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['token_hash']);

        self::assertIsString($row['issued_at']);
        self::assertEquals(new \DateTimeImmutable('2026-07-26T12:00:00+00:00'), new \DateTimeImmutable($row['issued_at']));

        self::assertIsString($row['expires_at']);
        self::assertEquals(new \DateTimeImmutable('2026-07-27T12:00:00+00:00'), new \DateTimeImmutable($row['expires_at']));

        self::assertNull($row['redeemed_at']);
    }

    /**
     * AC-2(a), AC-30: scans **every** column of the persisted request row for the plaintext token,
     * not merely `token_hash` — the guarantee AC-2 asks for is that the plaintext appears in no
     * column, and a test that only inspected `token_hash` would miss a future regression that
     * accidentally routed the plaintext into some other column (`id`, a hypothetical debug field,
     * anything added later). `RecordingVerificationMailer::lastToken()->reveal()` is the sanctioned
     * way to get at the plaintext from a test — see that fixture's own docblock — mirroring
     * `RequestEmailVerificationHandler`, the only other place in the system that legitimately holds
     * one.
     *
     * WHY THIS TEST STILL EARNS ITS PLACE TODAY. `EmailVerificationRequest::issue()` only ever
     * accepts a `HashedVerificationToken`, so there is currently no code path that could put the
     * plaintext in any column — the type system makes this unwritable, full stop. But a type system
     * only protects the code that exists today; it says nothing about a future
     * `issue(string $token)` convenience overload that skips the value object and hashes inline.
     * This test is what turns that guarantee into something that fails loudly the moment someone
     * writes that overload, instead of relying on nobody ever adding it.
     */
    public function testNoColumnOfThePersistedRequestRowEverContainsThePlaintextToken(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('ac2-plaintext-scan@example.com')]);

        ($this->handler)(new RequestEmailVerification('ac2-plaintext-scan@example.com'));

        $plaintext = $this->mailer->lastToken()->reveal();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM identity_email_verification_request WHERE user_id = ?',
            [$user->id()->toString()],
        );
        self::assertIsArray($row);
        self::assertNotSame([], $row, 'Expected the request row to have at least one column to scan.');

        foreach ($row as $column => $value) {
            // `fetchAssociative()` types every value `mixed`, so a bare `(string) $value` is a
            // PHPStan `cast.string` error at max level. Every column this table can ever hold is a
            // DBAL scalar (`uuid`, `varchar`, `timestamptz`) or `NULL`, never an object or array, so
            // `is_scalar()` is a safe narrowing rather than a workaround — and the `default` arm
            // turns a genuinely unexpected shape into a loud failure instead of a silently-skipped
            // column.
            $stringValue = match (true) {
                null === $value => '',
                \is_scalar($value) => (string) $value,
                default => throw new \LogicException(\sprintf('Column "%s" held a non-scalar value the plaintext scan cannot inspect.', $column)),
            };

            self::assertStringNotContainsString(
                $plaintext,
                $stringValue,
                \sprintf('Column "%s" must never contain the plaintext verification token.', $column),
            );
        }
    }

    /**
     * `issuedAt` comes from the `Clock` port, not the wall clock — a frozen instant far from "now"
     * ends up on the stored row.
     */
    public function testIssuedAtComesFromTheClockPortNotTheWallClock(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('clocked-verification@example.com')]);

        ($this->handler)(new RequestEmailVerification('clocked-verification@example.com'));

        $issuedAt = $this->connection->fetchOne(
            'SELECT issued_at FROM identity_email_verification_request WHERE user_id = ?',
            [$user->id()->toString()],
        );
        self::assertIsString($issuedAt);
        self::assertEquals(new \DateTimeImmutable('2026-07-26T12:00:00+00:00'), new \DateTimeImmutable($issuedAt));
    }

    public function testAlreadyVerifiedUserThrowsAndPersistsNothingAndSendsNoMail(): void
    {
        $user = UserFactory::new(['email' => Email::fromString('already-done@example.com')])->verified()->create();

        $this->expectException(EmailAlreadyVerified::class);

        try {
            ($this->handler)(new RequestEmailVerification('already-done@example.com'));
        } finally {
            self::assertSame(0, $this->requestRowCountFor($user->id()->toString()));
            self::assertSame(0, $this->mailer->count());
            self::assertSame([], $this->events->dispatched());
        }
    }

    public function testUnknownEmailThrowsUserNotFound(): void
    {
        $this->expectException(UserNotFound::class);

        try {
            ($this->handler)(new RequestEmailVerification('nobody-here@example.com'));
        } finally {
            self::assertSame(0, $this->mailer->count());
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * AC-17: the 6th issuance for the same user inside a rolling hour throws
     * `TooManyVerificationRequests` and persists no new row — invariant I-12, enforced by the
     * handler over `countIssuedForUserSince()`.
     */
    public function testTheSixthCallWithinAnHourThrowsTooManyVerificationRequestsAndPersistsNothing(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('rate-limited@example.com')]);

        for ($i = 1; $i <= EmailVerificationRequest::MAX_ISSUES_PER_HOUR; ++$i) {
            ($this->handler)(new RequestEmailVerification('rate-limited@example.com'));
        }

        self::assertSame(EmailVerificationRequest::MAX_ISSUES_PER_HOUR, $this->requestRowCountFor($user->id()->toString()));
        self::assertSame(EmailVerificationRequest::MAX_ISSUES_PER_HOUR, $this->mailer->count());

        $this->expectException(TooManyVerificationRequests::class);

        try {
            ($this->handler)(new RequestEmailVerification('rate-limited@example.com'));
        } finally {
            self::assertSame(EmailVerificationRequest::MAX_ISSUES_PER_HOUR, $this->requestRowCountFor($user->id()->toString()), 'The 6th call must not have persisted a new row.');
            self::assertSame(EmailVerificationRequest::MAX_ISSUES_PER_HOUR, $this->mailer->count(), 'The 6th call must not have sent a mail.');
        }
    }

    private function requestRowCountFor(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_email_verification_request WHERE user_id = ?',
            [$userId],
        );

        if (!is_numeric($count)) {
            self::fail('Expected COUNT(*) to return a numeric value.');
        }

        return (int) $count;
    }
}
