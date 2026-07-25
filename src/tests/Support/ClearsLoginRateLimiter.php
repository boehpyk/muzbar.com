<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Cache\CacheItemPoolInterface;

/**
 * GOTCHA (see the QA brief): the login throttle counters live in the *real* Redis-backed
 * `cache.rate_limiter` pool in every environment, test included — `cache.app` is
 * `cache.adapter.redis` everywhere and `cache.rate_limiter` is an explicit Redis pool (only
 * *sessions* fall back to `mock_file` under `when@test`). DAMA's per-test transaction rollback
 * only covers Postgres; it does nothing for Redis. Without this, failed-login counters would
 * accumulate across every test in the suite (and across suite runs), making later tests fail
 * non-deterministically depending on run order and history — exactly the "flaky for no reason
 * anyone can see in the diff" bug this trait exists to rule out structurally rather than by
 * convention (e.g. "remember to use a unique username").
 *
 * Any Functional test that drives `/login` uses this in `setUp()`.
 */
trait ClearsLoginRateLimiter
{
    private function clearLoginRateLimiterPool(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $pool->clear();
    }
}
