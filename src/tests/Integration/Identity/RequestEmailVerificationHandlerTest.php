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
