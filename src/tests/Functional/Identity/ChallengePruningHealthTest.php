<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Identity\ValueObject\HashedResetToken;
use App\Domain\Identity\ValueObject\HashedVerificationToken;
use App\Domain\Identity\ValueObject\UserId;
use App\Infrastructure\Http\Controller\HealthController;
use App\Infrastructure\Shared\Clock\SystemClock;
use App\Tests\Fixture\FrozenClock;
use App\Tests\Support\ClearsPruningState;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `/health/ready`'s `jobs.challenge_pruning` section — **reported, never gated on**.
 *
 * The whole point of this file is the status code. Every test below drives the endpoint into a state
 * where a well-meaning future change might reasonably 503 — no heartbeat, an ancient heartbeat, a
 * large backlog — and asserts **200**. Readiness answers *"should traffic come to this instance?"*,
 * and a housekeeping job that stopped is not an answer to it: a 503 here would take a healthy
 * container out of rotation and, behind a restart policy, restart it, whereupon the new container
 * would find the same un-swept rows and 503 again. A hygiene problem with an hours-long tolerance
 * turned into a restart loop by the probe meant to prevent outages.
 */
final class ChallengePruningHealthTest extends WebTestCase
{
    use ClearsPruningState;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->clearPruningState();
    }

    protected function tearDown(): void
    {
        $this->clearPruningState();

        parent::tearDown();
    }

    /**
     * AC-22: the section exists and carries exactly the five documented fields.
     *
     * Asserted exhaustively in both directions — no missing field, no extra one — because the shape is
     * a contract something outside this repository will eventually parse.
     */
    public function testTheSectionIsPresentAndCorrectlyShaped(): void
    {
        $body = $this->readyBody();

        self::assertArrayHasKey('jobs', $body);
        self::assertIsArray($body['jobs']);
        self::assertArrayHasKey('challenge_pruning', $body['jobs']);
        self::assertIsArray($body['jobs']['challenge_pruning']);

        $expected = ['last_run', 'age_seconds', 'overdue_verification', 'overdue_reset', 'stale'];
        $actual = array_keys($body['jobs']['challenge_pruning']);

        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * AC-22 / AC-23: with **no** heartbeat at all, `last_run` and `age_seconds` are null, `stale` is
     * true — and the endpoint still returns **200**.
     *
     * "Never run" and "healthy" are different claims, and only one of them belongs in a status code.
     */
    public function testAnAbsentHeartbeatIsStaleAndStillReturnsTwoHundred(): void
    {
        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);

        $section = $this->pruningSection();

        self::assertNull($section['last_run']);
        self::assertNull($section['age_seconds']);
        self::assertTrue($section['stale']);
    }

    /**
     * AC-23: an **ancient** heartbeat is stale and still returns 200.
     */
    public function testAnAncientHeartbeatIsStaleAndStillReturnsTwoHundred(): void
    {
        $this->setPruningHeartbeat(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'));

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);

        $section = $this->pruningSection();

        self::assertSame('2020-01-01T00:00:00+00:00', $section['last_run']);
        self::assertIsInt($section['age_seconds']);
        self::assertGreaterThan(HealthController::PRUNING_STALE_AFTER_SECONDS, $section['age_seconds']);
        self::assertTrue($section['stale']);
    }

    /**
     * AC-22, **both sides of the three-hour boundary**, in one test and against each other.
     *
     * Asserting only the stale side would pass with `>`, with `>=`, and with a threshold of one
     * second. The boundary instant itself must be **not** stale: `stale` is
     * `age_seconds > 10800`, so exactly three hours is still fine and one second later is not. Split
     * across two tests, the halves could drift apart while both stayed green.
     *
     * **Why this pins the `Clock` rather than reading `age_seconds` off two independent wall-clock
     * readings (the original form of this test).** The heartbeat used to be written at
     * `$now - 10800` using the *test's* `\DateTimeImmutable('now')`, while `HealthController` derives
     * `age_seconds` from its own, later, `Clock::now()` reading. Any real time elapsing between those
     * two reads — a scheduler hiccup, a slow CI runner — pushes `age_seconds` past 10800 and fails the
     * "not stale" half; reproduced empirically at roughly 6% of runs. The stale half already carried a
     * two-second margin for exactly this reason, which is itself the tell: a margin is required only
     * when the two readings are not guaranteed to agree.
     *
     * The fix removes the disagreement instead of widening the margin: `SystemClock` — the
     * **concrete** service id, never the `Clock` port alias, per `ResolveReferencesToAliasesPass`
     * (CLAUDE.md) — is swapped for a `FrozenClock`, so the controller's `Clock::now()` and this test's
     * `$now` are the *same* instant by construction. `$this->client->disableReboot()` is called before
     * the swap because `KernelBrowser` reboots the kernel before every request and would otherwise
     * discard the container override on the very first `request()` call. With the clock pinned, the
     * boundary is asserted **exactly** — `-10800` seconds is not stale, `-10801` is — with no fudge
     * factor of any width.
     */
    public function testStaleFlipsExactlyAtTheThreeHourBoundary(): void
    {
        $this->client->disableReboot();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::getContainer()->set(SystemClock::class, new FrozenClock($now));

        $this->setPruningHeartbeat($now->modify(\sprintf('-%d seconds', HealthController::PRUNING_STALE_AFTER_SECONDS)));
        $this->client->request('GET', '/health/ready');
        self::assertResponseStatusCodeSame(200);
        $atBoundary = $this->pruningSection();

        $this->setPruningHeartbeat($now->modify(\sprintf('-%d seconds', HealthController::PRUNING_STALE_AFTER_SECONDS + 1)));
        $this->client->request('GET', '/health/ready');
        self::assertResponseStatusCodeSame(200);
        $pastBoundary = $this->pruningSection();

        self::assertFalse($atBoundary['stale'], 'Exactly 10800 s old is NOT stale — the comparison is a strict >.');
        self::assertTrue($pastBoundary['stale'], 'Past 10800 s is stale.');
    }

    /**
     * AC-23: a **large backlog** does not move the status code either.
     *
     * This is the state a future reader is most likely to think deserves a 503, so it gets its own
     * test with the reason in the name.
     */
    public function testALargeBacklogDoesNotChangeTheStatusCode(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->persistOverdueVerification();
            $this->persistOverdueReset();
        }

        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ready', $this->readyBody()['status']);
    }

    /**
     * **AC-24 — the slice's entire observability claim, as one test with two phases.**.
     *
     * The claim is that *"the job stopped"* and *"the job has nothing to do"* are **structurally
     * distinguishable without trusting the job's own report**. Phase one establishes the healthy
     * reading; phase two inserts overdue rows and deliberately does **not** sweep them, and the
     * backlog moves. Nothing about the job's own account of itself is consulted in either phase —
     * that is the point. A heartbeat can be written by a job that runs and does nothing; a backlog
     * cannot be faked, and it survives Redis being flushed.
     *
     * **This is deliberately one test rather than two.** Split into "reports zero when empty" and
     * "reports non-zero when overdue", the halves could drift apart — one could start passing for the
     * wrong reason, or be deleted as redundant — and the *difference* between them, which is the
     * whole assertion, would be recorded nowhere. The two phases compare against **each other**.
     */
    public function testAStoppedJobIsDistinguishableFromAJobWithNothingToDo(): void
    {
        // Phase one: nothing overdue. This is what a healthy, freshly-swept system looks like.
        $this->client->request('GET', '/health/ready');
        self::assertResponseStatusCodeSame(200);
        $quiet = $this->pruningSection();

        self::assertSame(0, $quiet['overdue_verification']);
        self::assertSame(0, $quiet['overdue_reset']);

        // Phase two: overdue rows exist and NOTHING SWEEPS THEM — the job has stopped, or was never
        // started, or its crontab was lost. No command is run here on purpose.
        $this->persistOverdueVerification();
        $this->persistOverdueVerification();
        $this->persistOverdueReset();

        $this->client->request('GET', '/health/ready');
        self::assertResponseStatusCodeSame(200);
        $stopped = $this->pruningSection();

        self::assertGreaterThan(
            $quiet['overdue_verification'],
            $stopped['overdue_verification'],
            'A stopped job must be visible as a GROWING backlog, not merely as a missing heartbeat.',
        );
        self::assertGreaterThan($quiet['overdue_reset'], $stopped['overdue_reset']);
        self::assertSame(2, $stopped['overdue_verification']);
        self::assertSame(1, $stopped['overdue_reset']);
    }

    /**
     * @return array<mixed>
     */
    private function readyBody(): array
    {
        $this->client->request('GET', '/health/ready');

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);

        /* @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The `jobs.challenge_pruning` object from the response the client last received.
     *
     * Reads the *existing* response rather than issuing another request, so a test that asserted a
     * status code and then a body is talking about one response rather than two.
     *
     * @return array{last_run: string|null, age_seconds: int|null, overdue_verification: int|null, overdue_reset: int|null, stale: bool}
     */
    private function pruningSection(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $body = json_decode($content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('jobs', $body);
        self::assertIsArray($body['jobs']);
        self::assertArrayHasKey('challenge_pruning', $body['jobs']);
        self::assertIsArray($body['jobs']['challenge_pruning']);

        /** @var array{last_run: string|null, age_seconds: int|null, overdue_verification: int|null, overdue_reset: int|null, stale: bool} $section */
        $section = $body['jobs']['challenge_pruning'];

        return $section;
    }

    private function persistOverdueVerification(): void
    {
        $repository = self::getContainer()->get(EmailVerificationRequestRepository::class);
        self::assertInstanceOf(EmailVerificationRequestRepository::class, $repository);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $request = EmailVerificationRequest::issue(
            $repository->nextIdentity(),
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedVerificationToken::fromString(hash('sha256', random_bytes(32))),
            $now->modify(\sprintf(
                '-%d seconds',
                EmailVerificationRequest::LIFETIME_SECONDS + EmailVerificationRequest::RETENTION_AFTER_EXPIRY_SECONDS + 86400,
            )),
        );
        $request->releaseEvents();
        $repository->save($request);
    }

    private function persistOverdueReset(): void
    {
        $repository = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $repository);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $request = PasswordResetRequest::issue(
            $repository->nextIdentity(),
            UserId::fromString(Uuid::v7()->toRfc4122()),
            HashedResetToken::fromString(hash('sha256', random_bytes(32))),
            $now->modify(\sprintf(
                '-%d seconds',
                PasswordResetRequest::LIFETIME_SECONDS + PasswordResetRequest::RETENTION_AFTER_EXPIRY_SECONDS + 86400,
            )),
        );
        $request->releaseEvents();
        $repository->save($request);
    }
}
