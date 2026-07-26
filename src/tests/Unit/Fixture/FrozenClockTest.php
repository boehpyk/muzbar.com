<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fixture;

use App\Tests\Fixture\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * `FrozenClock` implements the `Clock` port (`Domain\Shared\Port\Clock`), not a Domain class
 * itself, so this lives under `Unit/Fixture` rather than `Unit/Domain` — it is a pure, no-kernel
 * unit test of a test double, not of the Domain.
 *
 * The port documents two mandatory clauses production's `SystemClock` upholds: `now()` must be
 * UTC, and it must be whole-second precision (see `Clock`'s and `SystemClock`'s docblocks for why
 * — Doctrine's `datetimetz_immutable` silently drops fractional seconds on write). `FrozenClock`
 * used to hand back whatever instant it was constructed with, uncoerced, which made it more
 * permissive than production: a test freezing at a sub-second, non-UTC instant would exercise a
 * precision and a zone the real system can never produce.
 */
final class FrozenClockTest extends TestCase
{
    public function testNowIsCoercedToUtcWholeSecondPrecisionWithoutMovingTheInstant(): void
    {
        $frozenAt = new \DateTimeImmutable('2026-07-26 15:34:56.789012', new \DateTimeZone('Europe/Kyiv'));

        $now = (new FrozenClock($frozenAt))->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertSame(0, (int) $now->format('u'));
        // Europe/Kyiv is UTC+3 in July (DST): 15:34:56 there is 12:34:56 UTC. Re-labelling must not
        // move the instant, so the two must remain the same absolute moment.
        self::assertSame($frozenAt->getTimestamp(), $now->getTimestamp());
        self::assertSame('2026-07-26T12:34:56+00:00', $now->format('Y-m-d\TH:i:sP'));
    }
}
