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
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
