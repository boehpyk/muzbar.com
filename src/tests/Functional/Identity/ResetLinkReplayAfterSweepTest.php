<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Exception\PasswordResetLinkAlreadyUsed;
use App\Domain\Identity\Exception\PasswordResetRequestNotFound;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\Port\ResetTokenGenerator;
use App\Domain\Identity\ValueObject\Email;
use App\Domain\Identity\ValueObject\UserId;
use App\Tests\Factory\PasswordResetRequestFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\RecordingLogger;
use App\Tests\Support\ClearsPruningState;
use App\Tests\Support\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * **AC-6 end to end: what the retention window actually buys, and what losing it would cost.**.
 *
 * Every other test in this slice asserts that some row survived or did not. This one asserts the
 * *consequence*, through the real HTTP flow, because AC-6 is the only acceptance criterion whose
 * whole justification is a difference a visitor cannot see.
 *
 * THE TRAP THIS FILE IS BUILT TO AVOID. Replaying a spent reset link and replaying a link whose row
 * has been swept produce the **identical** response — same 302, same `Location`, same neutral flash
 * (slice 3's AC-16, a presentation policy chosen to defeat account enumeration). So a test that
 * asserted only the HTTP response **would pass either way**, including on the day a bad retention
 * window swept the row an hour after it was redeemed. It would be a comment with a green tick next
 * to it.
 *
 * The distinction lives in exactly one place — the log line the controller writes, carrying
 * `reason => $e::class` — so that is what this test reads. `PasswordResetLinkAlreadyUsed` means the
 * system still knows this was a real challenge that was spent; `PasswordResetRequestNotFound` means
 * it has forgotten, and an incident review asking *"was this account's password reset from a link the
 * owner requested?"* has lost its answer.
 *
 * Both phases assert the response too, and **against each other rather than against two copies of a
 * literal**: proving they are indistinguishable is the other half of the claim, and two literals
 * could drift apart while both stayed green.
 */
final class ResetLinkReplayAfterSweepTest extends WebTestCase
{
    use ClearsPruningState;
    use ClearsRateLimiters;

    private KernelBrowser $client;
    private PasswordResetRequestRepository $requests;
    private ResetTokenGenerator $tokens;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
        $this->clearPruningState();

        $requests = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $requests);
        $this->requests = $requests;

        $tokens = self::getContainer()->get(ResetTokenGenerator::class);
        self::assertInstanceOf(ResetTokenGenerator::class, $tokens);
        $this->tokens = $tokens;
    }

    protected function tearDown(): void
    {
        $this->clearPruningState();

        parent::tearDown();
    }

    /**
     * AC-6: a redeemed row inside its retention window survives a sweep, so replaying its link still
     * reports `PasswordResetLinkAlreadyUsed` — **not** `PasswordResetRequestNotFound`.
     *
     * The sweep here is the real one, at the real threshold, over a row that was redeemed moments
     * ago: thirty days is exactly what stands between this row and deletion, and this test is the
     * only place that fact is observable from outside.
     */
    public function testReplayingASpentLinkAfterASweepStillReportsAlreadyUsed(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('replay-after-sweep@example.com')]);
        [$token, $requestId] = $this->issueRedeemedRequestFor($user->id());

        $logger = new RecordingLogger();
        $this->client->disableReboot();
        self::getContainer()->set('monolog.logger', $logger);

        // The real sweep, at the real threshold, with nothing special about this row.
        $deleted = $this->requests->deleteExpiredBefore(
            PasswordResetRequest::retentionThreshold(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            1000,
        );

        self::assertSame(0, $deleted, 'A row redeemed moments ago is nowhere near its thirty-day retention threshold.');
        self::assertTrue($this->rowExists($requestId), 'The sweep must have left the spent row exactly where it was.');

        $this->client->request('GET', '/reset-password/'.rawurlencode($token));

        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            PasswordResetLinkAlreadyUsed::class,
            $this->lastLinkFailureReason($logger),
            'The retention window exists precisely so this reads AlreadyUsed rather than NotFound.',
        );
    }

    /**
     * The counterfactual, and the reason the test above is not a tautology: **once the row is gone,
     * the same replay reports `PasswordResetRequestNotFound` — and the visitor cannot tell.**.
     *
     * The row is removed here by the ordinary sweep over a request old enough to be overdue, not by a
     * hand-written `DELETE`, so what is being demonstrated is the real mechanism reaching its real
     * conclusion. This is what a too-short retention window would do to every spent link.
     *
     * The two responses are compared **against each other**, live, rather than against two copies of
     * a literal — the rule for assertions about indistinguishability. If they ever diverge, the
     * neutral-response guarantee that defeats account enumeration has broken, and this test says so
     * even though neither response on its own would look wrong.
     */
    public function testOnceSweptTheSameReplayReportsNotFoundAndTheVisitorCannotTell(): void
    {
        $user = UserFactory::createOne(['email' => Email::fromString('replay-swept@example.com')]);

        // A spent link inside its window — the AC-6 case, for the response comparison below.
        [$liveToken] = $this->issueRedeemedRequestFor($user->id());

        // A spent link old enough to be overdue: redeemed, and issued long enough ago that its
        // expires_at is past the thirty-day threshold.
        [$overdueToken, $overdueId] = $this->issueRedeemedRequestFor($user->id(), overdue: true);

        $logger = new RecordingLogger();
        $this->client->disableReboot();
        self::getContainer()->set('monolog.logger', $logger);

        $deleted = $this->requests->deleteExpiredBefore(
            PasswordResetRequest::retentionThreshold(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            1000,
        );

        self::assertSame(1, $deleted, 'Exactly the overdue row goes.');
        self::assertFalse($this->rowExists($overdueId));

        $this->client->request('GET', '/reset-password/'.rawurlencode($overdueToken));
        $sweptStatus = $this->client->getResponse()->getStatusCode();
        $sweptLocation = $this->client->getResponse()->headers->get('Location');
        $sweptReason = $this->lastLinkFailureReason($logger);

        $this->client->request('GET', '/reset-password/'.rawurlencode($liveToken));
        $keptStatus = $this->client->getResponse()->getStatusCode();
        $keptLocation = $this->client->getResponse()->headers->get('Location');
        $keptReason = $this->lastLinkFailureReason($logger);

        // The logs differ — which is the entire value of the retention window.
        self::assertSame(PasswordResetRequestNotFound::class, $sweptReason);
        self::assertSame(PasswordResetLinkAlreadyUsed::class, $keptReason);
        self::assertNotSame($sweptReason, $keptReason);

        // The visitor cannot tell — which is why asserting the response alone would have proved
        // nothing at all, and why the two are compared live rather than against literals.
        self::assertSame($keptStatus, $sweptStatus);
        self::assertSame($keptLocation, $sweptLocation);
    }

    /**
     * Issues a request for `$userId`, redeems it, and returns the plaintext token with the row id.
     *
     * The token pair is built through `ResetTokenGenerator` because that is the only way a test can
     * know the plaintext a link's URL must carry — the real flow never exposes it anywhere readable.
     * The factory goes through `issue()` and the redemption through `redeem()`, so nothing here
     * fabricates a state the aggregate would refuse.
     *
     * @return array{string, string} the plaintext token and the request id
     */
    private function issueRedeemedRequestFor(UserId $userId, bool $overdue = false): array
    {
        $token = $this->tokens->generate();
        $hash = $this->tokens->hash($token);

        $factory = PasswordResetRequestFactory::new(['userId' => $userId, 'tokenHash' => $hash]);

        if ($overdue) {
            $factory = $factory->expiredLongAgo();
        }

        // Redeemed at issuance time, so an overdue row is redeemed *and* long past its window while
        // a fresh one is redeemed and nowhere near it — the only difference between the two cases.
        $request = $factory->create();
        $request->redeem($request->tokenHash(), $request->issuedAt()->modify('+1 minute'));
        $this->requests->save($request);

        return [$token->reveal(), $request->id()->toString()];
    }

    /**
     * The `reason` from the most recent "link could not be opened" record.
     *
     * The controller collapses nine causes into one neutral response and records which one it was in
     * this single field. It is the only surface on which AC-6's distinction is observable, which is
     * why this file reads the log rather than the page.
     */
    private function lastLinkFailureReason(RecordingLogger $logger): ?string
    {
        $reason = $logger->last()['context']['reason'] ?? null;

        return \is_string($reason) ? $reason : null;
    }

    private function rowExists(string $id): bool
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        /** @var int|numeric-string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM identity_password_reset_request WHERE id = :id',
            ['id' => $id],
        );

        return (bool) (int) $count;
    }
}
