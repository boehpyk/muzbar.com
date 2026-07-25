<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Domain\Shared\Port\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * A test double for the `Clock` port, backed by Symfony's own `MockClock` — the same mechanism
 * `Infrastructure\Shared\Clock\SystemClock` wraps in production, frozen instead of live.
 *
 * Exists so `RegisterUserHandlerTest`/`VerifyUserEmailHandlerTest` can prove `registeredAt` /
 * `emailVerifiedAt` come from the `Clock` port and not from the wall clock (technical plan, Test
 * plan: "a fixed Clock stub proves `registeredAt` comes from the port").
 */
final readonly class FrozenClock implements Clock
{
    private MockClock $clock;

    public function __construct(\DateTimeImmutable $frozenAt)
    {
        $this->clock = new MockClock($frozenAt);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
