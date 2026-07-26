<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Clock;

use App\Domain\Shared\Port\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * The `Clock` port, backed by Symfony's clock service.
 *
 * WHY WRAP SYMFONY'S CLOCK RATHER THAN CALL `new \DateTimeImmutable()`.
 * Symfony's clock service is swappable at the container level: a test can bind
 * `Symfony\Component\Clock\MockClock` and every timestamp in the system — registration,
 * verification, anything a future context adds — moves with it, without a single production class
 * knowing it is being lied to. Constructing the date here directly would make this adapter itself
 * untestable and would push every test back to "roughly now, give or take".
 *
 * WHY THE TIMEZONE IS FORCED HERE AND NOT TRUSTED.
 * The `Clock` port documents a UTC contract, and a contract that depends on the container's
 * `date.timezone` is not a contract. `MockClock` in particular is routinely constructed with a
 * local zone. `setTimezone()` re-labels the instant without moving it, so the absolute time is
 * untouched and only its presentation is pinned — which is exactly what makes log lines and test
 * assertions read the same on a laptop in Kyiv and on the VDS.
 *
 * WHY THE MICROSECONDS ARE THROWN AWAY HERE, AT THE SOURCE.
 * Persistence cannot keep them. Doctrine's `datetimetz_immutable` formats through
 * `PostgreSQLPlatform::getDateTimeTzFormatString()`, which is `'Y-m-d H:i:sO'` — the *type* drops
 * the fractional part before the column's `TIMESTAMP(0)` precision is ever consulted, so widening
 * the column would change nothing. Left un-truncated, a timestamp would therefore mean two
 * different instants depending on where you read it from: within one request the identity map hands
 * back the in-memory object with its microseconds intact, while after an `EntityManager::clear()`
 * or in any later request the same aggregate reports the truncated value. Comparisons like
 * `registeredAt == $clock->now()` would then pass or fail based on whether Doctrine happened to
 * have the object cached.
 *
 * Truncating here makes "the instant the Domain saw" and "the instant the database stores" the same
 * value by construction, and does it in the one place every timestamp in the system already flows
 * through — rather than in each aggregate, each mapping, or each assertion. `setTime()` rebuilds the
 * value from the instant's own UTC clock fields with an explicit zero microsecond argument, so
 * nothing is formatted to a string and reparsed, and no arithmetic can drift.
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        $utc = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
            0,
        );
    }
}
