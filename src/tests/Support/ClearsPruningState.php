<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Identity\Console\PruneChallengesCommand;
use Predis\ClientInterface as RedisClient;

/**
 * Clears the two Redis keys `identity-challenge-pruning` added — the run lock and the heartbeat.
 *
 * SAME HAZARD AS `ClearsRateLimiters`, DIFFERENT MECHANISM, AND THAT IS WHY THIS IS A SECOND TRAIT
 * RATHER THAN TWO MORE LINES IN THE FIRST. The hazard is the one CLAUDE.md already documents: **DAMA
 * rolls back Postgres and does nothing whatsoever for Redis**, so anything written there outlives the
 * test that wrote it, and outlives the whole suite run. The mechanism is not the same, though, and
 * conflating them would produce a trait that silently only half worked. `ClearsRateLimiters` clears
 * the `cache.rate_limiter` **pool** through `CacheItemPoolInterface::clear()`; every limiter counter
 * lives inside that pool, under Symfony's own cache-key namespacing. These two keys are written by
 * `PruneChallengesCommand` straight through `Predis\ClientInterface` with no pool and no namespace, so
 * `$pool->clear()` cannot see them and never will. Extending the rate-limiter trait would therefore
 * have meant a trait whose name promised one thing, whose body did two things by two different routes,
 * and whose failure mode was that the half nobody tested quietly did nothing.
 *
 * WHY A LEFTOVER KEY IS WORSE HERE THAN A LEFTOVER RATE-LIMIT COUNTER, and the reason this trait is
 * load-bearing rather than tidy. A stale limiter counter makes a later test fail — annoying,
 * order-dependent, but *loud*. A stale **run lock** makes `muzbar:identity:prune-challenges` do
 * nothing, log `skipped: lock_held` and **exit 0**. A test asserting a successful run would then be
 * asserting against a run that never happened, and would pass. That is the shape of bug this
 * repository cares about most: not a red suite, but a green one that has stopped meaning anything.
 * A stale **heartbeat** is the same story one endpoint over — `/health/ready` would report a pruner
 * that had run recently because a *different test* ran it, so a test pinning `stale: true` on an
 * absent heartbeat would fail or pass depending on file order.
 *
 * Both keys are deleted rather than expired: `HEARTBEAT_KEY` is written with no TTL on purpose (see
 * its constant), so nothing else will ever remove it.
 *
 * Used in `setUp()` by every test that runs the command, and by every test that reads
 * `/health/ready`'s `jobs.challenge_pruning` section. **The cheap proof that it is wired correctly is
 * `make test` twice in a row** (AC-42) — a second run that fails is the classic symptom.
 */
trait ClearsPruningState
{
    private function clearPruningState(): void
    {
        $redis = self::getContainer()->get(RedisClient::class);
        self::assertInstanceOf(RedisClient::class, $redis);

        $redis->del([
            PruneChallengesCommand::LOCK_KEY,
            PruneChallengesCommand::HEARTBEAT_KEY,
        ]);
    }

    /**
     * Writes the heartbeat to a chosen instant, for the tests that need `/health/ready` to see an
     * ancient one without waiting three hours for it to become ancient.
     *
     * Deliberately takes a `\DateTimeImmutable` and formats it exactly as the command does, rather
     * than accepting a pre-formatted string: a test that hand-wrote the ISO-8601 could drift from the
     * producer's format and would then be asserting that the controller parses *the test's* idea of a
     * heartbeat rather than the one the command actually writes.
     */
    private function setPruningHeartbeat(\DateTimeImmutable $at): void
    {
        $redis = self::getContainer()->get(RedisClient::class);
        self::assertInstanceOf(RedisClient::class, $redis);

        $redis->set(PruneChallengesCommand::HEARTBEAT_KEY, $at->format(\DateTimeInterface::ATOM));
    }
}
