<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Domain\Identity\Entity\EmailVerificationRequest;
use App\Domain\Identity\Entity\PasswordResetRequest;
use App\Domain\Identity\Port\EmailVerificationRequestRepository;
use App\Domain\Identity\Port\PasswordResetRequestRepository;
use App\Domain\Shared\Port\Clock;
use App\Infrastructure\Identity\Console\PruneChallengesCommand;
use Doctrine\DBAL\Connection;
use Predis\ClientInterface as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness vs readiness — never a bare `return 'ok'` (Constitution §7, infrastructure.md).
 *
 * - /health/live  : the process is up. No dependency calls. For Docker/Traefik liveness.
 * - /health/ready : actively probes Postgres + Redis; 503 if any dependency is down.
 *
 * `/health/ready` additionally *reports* on background work it does not gate on — see
 * `challengePruningStatus()` for why "reports" and "gates" are two different words here.
 */
final class HealthController extends AbstractController
{
    /**
     * How old the pruning heartbeat may get before `/health/ready` calls it stale: 10 800 s.
     *
     * **Three missed hourly runs, not two, and the extra hour is deliberate.** A single skipped run
     * is an ordinary event with several benign causes — a lock still held by a long first sweep, a
     * deploy restarting the `app` container across the cron minute, a clock nudge. A threshold that
     * flagged one of those would produce an alarm the operator learns to ignore, which is worse than
     * no alarm, because the flag would still be `true` on the day it meant something. Three runs is
     * long enough that "nothing ran" is the only remaining explanation and short enough to notice
     * within a working day.
     */
    public const int PRUNING_STALE_AFTER_SECONDS = 10800;

    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'alive']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(
        Connection $db,
        RedisClient $redis,
        EmailVerificationRequestRepository $verificationRequests,
        PasswordResetRequestRepository $resetRequests,
        Clock $clock,
    ): JsonResponse {
        $checks = [
            'postgres' => $this->probe(static fn () => $db->executeQuery('SELECT 1')),
            'redis' => $this->probe(static fn () => $redis->ping()),
        ];

        $ready = !\in_array(false, array_column($checks, 'ok'), true);

        return $this->json(
            [
                'status' => $ready ? 'ready' : 'unavailable',
                'checks' => $checks,
                // REPORTING ONLY. `$ready` above is computed before this line and is not touched by
                // it — see `challengePruningStatus()` for the argument, which is the whole reason
                // the section is assembled here rather than folded into `$checks`.
                'jobs' => [
                    'challenge_pruning' => $this->challengePruningStatus(
                        $redis,
                        $verificationRequests,
                        $resetRequests,
                        $clock,
                    ),
                ],
            ],
            $ready ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * What `muzbar:identity:prune-challenges` has been up to — **reported, never gated on** (AC-22,
     * AC-23).
     *
     * WHY THIS CANNOT MOVE THE STATUS CODE, WHICH IS THE ONLY DECISION IN THIS METHOD. Readiness
     * answers exactly one question: *should traffic come to this instance?* A housekeeping job that
     * stopped is not an answer to it. The instance serves every request correctly with a month of
     * un-pruned rows sitting in two tables; nothing a visitor does touches them. So with the
     * heartbeat absent, with the heartbeat ancient, and with an enormous backlog, this endpoint
     * still returns 200 as long as Postgres and Redis answer.
     *
     * The concrete cost of getting it wrong is worth naming, because "surely a stalled job should
     * fail the health check" is the improvement a future reader will offer. `/health/ready` is what
     * Docker and Traefik consult. A 503 here takes a **healthy** container out of rotation and, with
     * a restart policy in front of it, restarts it — whereupon the new container finds the same
     * un-swept rows and 503s again. A hygiene problem with a hours-long tolerance would have been
     * converted into a restart loop and a genuine outage, by a probe whose job was to prevent one.
     * The signal belongs in the body, where something that reads bodies can act on it.
     *
     * WHY `overdue_*` IS THE FIELD TO TRUST AND `last_run` IS NOT (AC-24). A heartbeat can be
     * written by a job that runs and does nothing, and it vanishes entirely if Redis is flushed.
     * The backlog is counted straight out of Postgres against the two aggregates' own retention
     * thresholds: it is ~0 after every healthy run, it grows monotonically when nothing runs, and
     * **no amount of the job lying about itself can move it.** The heartbeat earns its place only as
     * the *early* signal — it distinguishes "has not run" from "ran and had nothing to do" before a
     * backlog has had time to build — which is exactly the job of a secondary.
     *
     * WHY NOTHING HERE MAY THROW, AND HOW THAT IS EXPRESSED. This section reads Redis and runs two
     * `COUNT`s; every one of those can fail. An uncaught failure would produce a 500 from the one
     * endpoint whose entire purpose is to answer while things are going wrong — and it would do it
     * *because of the reporting-only section*, which would then have changed the status code after
     * all, in the worst possible direction. So each reading is guarded independently and a failed
     * one reports `null` rather than propagating: the shape of the section is fixed, and a null
     * field says "could not read this" while the endpoint still delivers its actual verdict. The
     * guards are per-reading rather than one around the lot so that a Redis outage does not also
     * hide the backlog, which is the number that matters most precisely when something is down.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO. ADR-0010 left a genuinely open question — teaching
     * `/health/ready` about Messenger queue depth, after a session in which `messenger-worker`
     * crash-looped while this endpoint reported 200 throughout. That question stays **open and
     * separate**. Answering half of it here, in a slice about deleting rows, would leave the
     * repository with one background job observable and another not, and a precedent that looked
     * like the question had been settled.
     *
     * @return array{last_run: string|null, age_seconds: int|null, overdue_verification: int|null, overdue_reset: int|null, stale: bool}
     */
    private function challengePruningStatus(
        RedisClient $redis,
        EmailVerificationRequestRepository $verificationRequests,
        PasswordResetRequestRepository $resetRequests,
        Clock $clock,
    ): array {
        // `stale` starts `true` and is only ever argued down. An absent heartbeat means stale
        // (AC-22), an unreadable one means stale, and a garbled one means stale — all three are
        // "nothing here proves the job is alive", which is the same answer. Defaulting to `false`
        // and setting it on failure would make every new failure path silently report healthy.
        $status = [
            'last_run' => null,
            'age_seconds' => null,
            'overdue_verification' => null,
            'overdue_reset' => null,
            'stale' => true,
        ];

        try {
            $now = $clock->now();
        } catch (\Throwable) {
            return $status;
        }

        try {
            $raw = $redis->get(PruneChallengesCommand::HEARTBEAT_KEY);
            $lastRun = \is_string($raw)
                ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $raw)
                : false;

            if (false !== $lastRun) {
                // Clamped at zero. A heartbeat in the future means the clocks disagree, not that
                // the job ran in the future, and a negative `age_seconds` would be a number no
                // consumer knows what to do with. Clamping keeps such a run *not* stale, which is
                // the harmless direction: the backlog is still there to contradict it.
                $ageSeconds = max(0, $now->getTimestamp() - $lastRun->getTimestamp());

                // Reformatted rather than echoed back verbatim, so the field is ISO-8601 by
                // construction and a malformed value cannot reach a consumer wearing the shape of a
                // valid one — it has already failed the parse above and left `last_run` null.
                $status['last_run'] = $lastRun->format(\DateTimeInterface::ATOM);
                $status['age_seconds'] = $ageSeconds;
                $status['stale'] = $ageSeconds > self::PRUNING_STALE_AFTER_SECONDS;
            }
        } catch (\Throwable) {
            // Swallowed on purpose; see the docblock. Redis being unreachable is already reported
            // by `checks.redis`, and reporting it twice by way of a 500 would be the failure this
            // method exists to avoid.
        }

        try {
            // The thresholds come from the aggregates' own statics and a single `Clock` reading, so
            // this endpoint and the sweep judge "overdue" by the same rule and the same arithmetic.
            // Recomputing the window here — `now - 7 days` spelled out inline — would be a second
            // copy of a policy that lives on the aggregates, and the day one of them changed, the
            // health check would keep confidently reporting the old one.
            $status['overdue_verification'] = $verificationRequests->countExpiredBefore(
                EmailVerificationRequest::retentionThreshold($now),
            );
            $status['overdue_reset'] = $resetRequests->countExpiredBefore(
                PasswordResetRequest::retentionThreshold($now),
            );
        } catch (\Throwable) {
            // Same reasoning as above: Postgres being down already fails `checks.postgres` and
            // turns the response into a 503. It must not additionally turn it into a 500.
        }

        return $status;
    }

    /**
     * @param callable():mixed $probe
     *
     * @return array{ok: bool, error?: string}
     */
    private function probe(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
