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
 *
 * Deliberately mirrors the port's guarantees, exactly as `SystemClock` does in production: `now()`
 * is coerced to UTC and truncated to whole-second precision, even though the constructor accepts
 * whatever instant the test hands it. A test double that is more permissive than production is a
 * test double that hides bugs — one constructed with a sub-second or local-zone instant would
 * otherwise feed the Domain a precision and a zone production can never produce, silently
 * un-catching the exact class of bug `SystemClock` was fixed for (see its docblock).
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
        $utc = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
            0,
        );
    }
}
