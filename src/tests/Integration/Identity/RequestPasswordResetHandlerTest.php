<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Application\Identity\Command\RequestPasswordReset;
use App\Application\Identity\Handler\RequestPasswordResetHandler;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Event\PasswordResetRequested;
use App\Domain\Identity\Exception\PasswordResetLinkInvalidated;
use App\Domain\Identity\Exception\TooManyPasswordResetRequests;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Fixture\RecordingPasswordResetMailer;
use App\Tests\Fixture\SpyDomainEventDispatcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for `RequestPasswordResetHandler` against the real `muzbar_test` database (DAMA
 * rollback), with a frozen `Clock`, a spy `DomainEventDispatcher` and a recording
 * `PasswordResetMailer` double standing in for the real ones — the technical plan's Test plan
 * prescription exactly, mirroring `RequestEmailVerificationHandlerTest`.
 */
final class RequestPasswordResetHandlerTest extends KernelTestCase
{
    private UserRepository $users;
    private PasswordResetRequestRepository $requests;
    private ResetTokenGenerator $tokens;
    private RecordingPasswordResetMailer $mailer;
    private FrozenClock $clock;
    private SpyDomainEventDispatcher $events;
    private RequestPasswordResetHandler $handler;
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

        $this->mailer = new RecordingPasswordResetMailer();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-07-29T12:00:00+00:00'));
        $this->events = new SpyDomainEventDispatcher();

        $this->handler = new RequestPasswordResetHandler($this->users, $this->requests, $this->tokens, $this->mailer, $this->clock, $this->events);
    }

    /**
     * AC-2, AC-3, AC-32: the happy path persists exactly one row, sends exactly one message through
     * the mailer port, dispatches exactly one `PasswordResetRequested`, and the stored `expiresAt` is
     * `issuedAt + 3600` exactly.
     */
    public function testHappyPathPersistsOneRowSendsOneMessageAndDispatchesOneEvent(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('needs-reset@example.com')]);

        ($this->handler)(new RequestPasswordReset('needs-reset@example.com'));

        self::assertSame(1, $this->requestRowCountFor($user->id()->toString()));

        self::assertSame(1, $this->mailer->count());
        self::assertTrue($this->mailer->lastRecipient()->equals(Email::fromString('needs-reset@example.com')));
        self::assertSame(43, \strlen($this->mailer->lastToken()->reveal()));
        self::assertEquals(new \DateTimeImmutable('2026-07-29T13:00:00+00:00'), $this->mailer->lastExpiresAt());

        $row = $this->connection->fetchAssociative(
            'SELECT issued_at, expires_at, redeemed_at, invalidated_at FROM identity_password_reset_request WHERE user_id = ?',
            [$user->id()->toString()],
        );
        self::assertIsArray($row);
        self::assertIsString($row['issued_at']);
        self::assertEquals(new \DateTimeImmutable('2026-07-29T12:00:00+00:00'), new \DateTimeImmutable($row['issued_at']));
        self::assertIsString($row['expires_at']);
        self::assertEquals(new \DateTimeImmutable('2026-07-29T13:00:00+00:00'), new \DateTimeImmutable($row['expires_at']));
        self::assertSame(
            PasswordResetRequest::LIFETIME_SECONDS,
            (new \DateTimeImmutable($row['expires_at']))->getTimestamp() - (new \DateTimeImmutable($row['issued_at']))->getTimestamp(),
        );
        self::assertNull($row['redeemed_at']);
        self::assertNull($row['invalidated_at']);

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(PasswordResetRequested::class, $dispatched[0]);
        self::assertTrue($dispatched[0]->userId()->equals($user->id()));
        self::assertEquals(new \DateTimeImmutable('2026-07-29T12:00:00+00:00'), $dispatched[0]->issuedAt());
        self::assertEquals(new \DateTimeImmutable('2026-07-29T13:00:00+00:00'), $dispatched[0]->expiresAt());
    }

    /**
     * AC-10(a): scans **every** column of the persisted request row for the plaintext token, not
     * merely `token_hash` — the guarantee AC-10 asks for is that the plaintext appears in no column,
     * and a test that only inspected `token_hash` would miss a future regression that accidentally
     * routed the plaintext into some other column (`id`, a hypothetical debug field, anything added
     * later). `RecordingPasswordResetMailer::lastToken()->reveal()` is the sanctioned way to get at
     * the plaintext from a test — see that fixture's own docblock — mirroring
     * `RequestPasswordResetHandler`, the only other place in the system that legitimately holds one.
     * Ported from `RequestEmailVerificationHandlerTest::testNoColumnOfThePersistedRequestRowEverContainsThePlaintextToken()`
     * (AC-2(a) of slice 2), which this AC and this test structure both mirror.
     *
     * WHY THIS TEST STILL EARNS ITS PLACE TODAY. `PasswordResetRequest::issue()` only ever accepts a
     * `HashedResetToken`, so there is currently no code path that could put the plaintext in any
     * column — the type system makes this unwritable, full stop. But a type system only protects the
     * code that exists today; it says nothing about a future `issue(string $token)` convenience
     * overload that skips the value object and hashes inline. This test is what turns that guarantee
     * into something that fails loudly the moment someone writes that overload, instead of relying
     * on nobody ever adding it.
     */
    public function testNoColumnOfThePersistedRequestRowEverContainsThePlaintextToken(): void
    {
        UserFactory::createOne(['email' => Email::fromString('ac10-plaintext-scan@example.com')]);

        ($this->handler)(new RequestPasswordReset('ac10-plaintext-scan@example.com'));

        $plaintext = $this->mailer->lastToken()->reveal();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM identity_password_reset_request WHERE user_id = (SELECT id FROM identity_user WHERE email = ?)',
            ['ac10-plaintext-scan@example.com'],
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
                \sprintf('Column "%s" must never contain the plaintext reset token.', $column),
            );
        }
    }

    /**
     * AC-34, the handler-level half. The Domain unit test
     * (`PasswordResetRequestTest::testInvalidateRecordsNoEvent()`) already pins that a single
     * `invalidate()` call records nothing; this test pins the thing that would actually catch a
     * regression here: a **second** request for a user with an outstanding one must dispatch
     * **exactly one** more `PasswordResetRequested` (from the new `issue()`) and nothing extra from
     * sweeping the superseded row. `testASecondRequestInvalidatesThePreviousOutstandingRequestAndItsTokenNoLongerRedeems()`
     * above already asserts the row count and the swept row's `invalidatedAt`, but never the
     * dispatched-event count — so a bug that made the sweep emit one event per invalidated row (N
     * events for zero subscribers, AC-34) would stay green there. It would not stay green here.
     */
    public function testASecondRequestDispatchesExactlyOnePasswordResetRequestedAndNothingElse(): void
    {
        UserFactory::createOne(['email' => Email::fromString('reissue-event-count@example.com')]);

        ($this->handler)(new RequestPasswordReset('reissue-event-count@example.com'));
        self::assertCount(1, $this->events->dispatched(), 'The first issuance must dispatch exactly one event.');

        ($this->handler)(new RequestPasswordReset('reissue-event-count@example.com'));

        $dispatched = $this->events->dispatched();
        self::assertCount(2, $dispatched, 'The second issuance must dispatch exactly one MORE event, not one per invalidated row.');
        self::assertInstanceOf(PasswordResetRequested::class, $dispatched[1]);
    }

    /**
     * AC-32: `PasswordResetRequested` carries no token. `PasswordResetRequested` declares exactly
     * four constructor-promoted properties (`requestId`, `userId`, `issuedAt`, `expiresAt`), none
     * typed `ResetToken` or `HashedResetToken` — already pinned exhaustively at the Domain unit level
     * (`PasswordResetRequestTest`). This test additionally proves the *handler* dispatches that same
     * type rather than some other shape, by asserting the dispatched event's own reflection carries
     * no such property.
     */
    public function testDispatchedEventCarriesNoTokenProperty(): void
    {
        UserFactory::createOne(['email' => Email::fromString('no-token-in-event@example.com')]);

        ($this->handler)(new RequestPasswordReset('no-token-in-event@example.com'));

        $dispatched = $this->events->dispatched();
        self::assertCount(1, $dispatched);

        $propertyTypeNames = array_map(
            static fn (\ReflectionProperty $p): ?string => $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : null,
            (new \ReflectionClass($dispatched[0]))->getProperties(),
        );

        self::assertNotContains(\App\Domain\Identity\ValueObject\ResetToken::class, $propertyTypeNames);
        self::assertNotContains(\App\Domain\Identity\ValueObject\HashedResetToken::class, $propertyTypeNames);
    }

    /**
     * AC-9: issuing a second request invalidates the account's previous outstanding request — the
     * old row's `invalidated_at` is set, and presenting its own hash to `assertRedeemableWith()`
     * afterwards throws `PasswordResetLinkInvalidated` rather than succeeding, i.e. the old token no
     * longer redeems.
     */
    public function testASecondRequestInvalidatesThePreviousOutstandingRequestAndItsTokenNoLongerRedeems(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('reissue@example.com')]);

        ($this->handler)(new RequestPasswordReset('reissue@example.com'));
        $firstToken = $this->mailer->lastToken();
        $firstHash = $this->tokens->hash($firstToken);

        ($this->handler)(new RequestPasswordReset('reissue@example.com'));

        self::assertSame(2, $this->requestRowCountFor($user->id()->toString()));

        $firstRequest = $this->requests->findByTokenHash($firstHash);
        self::assertNotNull($firstRequest);
        self::assertTrue($firstRequest->isInvalidated());
        self::assertEquals(new \DateTimeImmutable('2026-07-29T12:00:00+00:00'), $firstRequest->invalidatedAt());

        $this->expectException(PasswordResetLinkInvalidated::class);
        $firstRequest->assertRedeemableWith($firstHash, $this->clock->now());
    }

    public function testUnknownEmailThrowsUserNotFoundAndPersistsNothing(): void
    {
        $this->expectException(UserNotFound::class);

        try {
            ($this->handler)(new RequestPasswordReset('nobody-here@example.com'));
        } finally {
            self::assertSame(0, $this->mailer->count());
            self::assertSame([], $this->events->dispatched());
        }
    }

    /**
     * AC-7: the 4th call inside the rolling hour throws `TooManyPasswordResetRequests`, persists no
     * new row, **and — the clause that actually matters — leaves the existing live request
     * outstanding.** A test that only asserted the throw would stay green if the cap check were moved
     * to run after the invalidation sweep, which is exactly the ordering bug AC-7 exists to prevent:
     * a spammed form must never be able to kill a victim's in-flight link. Capturing the outstanding
     * request's identity *before* the refused call and asserting it is the *same*, still-live request
     * afterwards is what would catch that reordering; asserting only the throw would not.
     */
    public function testTheFourthCallInsideTheHourThrowsAndLeavesTheExistingRequestOutstanding(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('capped@example.com')]);

        for ($i = 1; $i <= PasswordResetRequest::MAX_ISSUES_PER_HOUR; ++$i) {
            ($this->handler)(new RequestPasswordReset('capped@example.com'));
        }

        self::assertSame(PasswordResetRequest::MAX_ISSUES_PER_HOUR, $this->requestRowCountFor($user->id()->toString()));

        $outstandingBefore = $this->requests->findOutstandingForUser($user->id());
        self::assertCount(1, $outstandingBefore, 'each reissue invalidates the last, so exactly one request should still be live');
        $liveRequestId = $outstandingBefore[0]->id();

        $this->expectException(TooManyPasswordResetRequests::class);

        try {
            ($this->handler)(new RequestPasswordReset('capped@example.com'));
        } finally {
            self::assertSame(
                PasswordResetRequest::MAX_ISSUES_PER_HOUR,
                $this->requestRowCountFor($user->id()->toString()),
                'The 4th call must not have persisted a new row.',
            );
            self::assertSame(PasswordResetRequest::MAX_ISSUES_PER_HOUR, $this->mailer->count(), 'The 4th call must not have sent a mail.');

            $outstandingAfter = $this->requests->findOutstandingForUser($user->id());
            self::assertCount(1, $outstandingAfter, 'the refused 4th call must not have invalidated the existing live request');
            self::assertTrue(
                $outstandingAfter[0]->id()->equals($liveRequestId),
                'the existing live request must be the very same one that was live before the refused call',
            );
            self::assertNull($outstandingAfter[0]->invalidatedAt());
            self::assertNull($outstandingAfter[0]->redeemedAt());
        }
    }

    /**
     * The cap is a **rolling** hour, not a lifetime counter: three requests issued an hour-plus ago
     * plus one issued now succeeds, because the three old ones have fallen outside the window by the
     * time the new one is evaluated. A second `FrozenClock` advances time — never `sleep()`.
     */
    public function testTheRollingWindowAllowsAFourthRequestOnceTheEarlierOnesFallOutsideTheHour(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('rolling-window@example.com')]);

        for ($i = 1; $i <= PasswordResetRequest::MAX_ISSUES_PER_HOUR; ++$i) {
            ($this->handler)(new RequestPasswordReset('rolling-window@example.com'));
        }
        self::assertSame(PasswordResetRequest::MAX_ISSUES_PER_HOUR, $this->requestRowCountFor($user->id()->toString()));

        $laterClock = new FrozenClock(new \DateTimeImmutable('2026-07-29T13:01:40+00:00')); // +3700s
        $laterMailer = new RecordingPasswordResetMailer();
        $laterEvents = new SpyDomainEventDispatcher();
        $laterHandler = new RequestPasswordResetHandler($this->users, $this->requests, $this->tokens, $laterMailer, $laterClock, $laterEvents);

        $laterHandler(new RequestPasswordReset('rolling-window@example.com'));

        self::assertSame(PasswordResetRequest::MAX_ISSUES_PER_HOUR + 1, $this->requestRowCountFor($user->id()->toString()));
        self::assertSame(1, $laterMailer->count());
        self::assertCount(1, $laterEvents->dispatched());

        $outstanding = $this->requests->findOutstandingForUser($user->id());
        self::assertCount(1, $outstanding);
        self::assertEquals(new \DateTimeImmutable('2026-07-29T13:01:40+00:00'), $outstanding[0]->issuedAt());
    }

    private function requestRowCountFor(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_password_reset_request WHERE user_id = ?',
            [$userId],
        );

        if (!is_numeric($count)) {
            self::fail('Expected COUNT(*) to return a numeric value.');
        }

        return (int) $count;
    }
}
