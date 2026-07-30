<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Cache\CacheItemPoolInterface;

/**
 * GOTCHA (see the QA brief): every rate-limiter counter in this application lives in the *real*
 * Redis-backed `cache.rate_limiter` pool in every environment, test included — `cache.app` is
 * `cache.adapter.redis` everywhere and `cache.rate_limiter` is an explicit Redis pool (only
 * *sessions* fall back to `mock_file` under `when@test`). DAMA's per-test transaction rollback only
 * covers Postgres; it does nothing for Redis. Without this, counters would accumulate across every
 * test in the suite (and across suite runs), making later tests fail non-deterministically depending
 * on run order and history — exactly the "flaky for no reason anyone can see in the diff" bug this
 * trait exists to rule out structurally rather than by convention (e.g. "remember to use a unique
 * username").
 *
 * GENERALISED FROM `ClearsLoginRateLimiter` (identity-email-verification, T30). Slice 1's original
 * name described one limiter (`login_throttling`); slice 2 added a second, `verification_email_resend`
 * (`config/packages/rate_limiter.yaml`), sharing the *same* `cache.rate_limiter` pool. Clearing the
 * whole pool already cleared both — the method body below is unchanged from the original — but a
 * trait named after one limiter while silently protecting a second is exactly the kind of drift that
 * makes a reader distrust a docblock. Renaming it to name the pool it actually clears is what keeps
 * the trait honest as further limiters arrive and need nothing more than this same clear.
 *
 * `identity-password-reset` (slice 3) added **two more**, `password_reset_request` and
 * `password_reset_submit`, over the same pool — so the pool now backs **four** limiters in total:
 * `login_throttling`, `verification_email_resend`, `password_reset_request` and
 * `password_reset_submit`. Again, the body below does not change: `clear()` already clears
 * everything in the pool regardless of how many limiters share it. Only this docblock's inventory
 * needed updating, which is precisely the drift CLAUDE.md warns about — a docblock naming two
 * limiters while the trait actually protects four.
 *
 * Any Functional test that drives `/login`, `/verify-email/resend`, `/forgot-password` or the
 * `/reset-password*` routes uses this in `setUp()`.
 */
trait ClearsRateLimiters
{
    private function clearRateLimiterPool(): void
    {
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $pool->clear();
    }
}
