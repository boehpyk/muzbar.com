<?php

declare(strict_types=1);

namespace App\Domain\Shared\Port;

/**
 * The Domain's only source of "now".
 *
 * Reaching for `new \DateTimeImmutable()` inside an aggregate or a handler makes time an
 * untestable global: assertions about `registeredAt` become "roughly now, give or take", and
 * time-dependent rules can only be tested by waiting. Behind a port, time is an injected value
 * that a test can freeze.
 *
 * Implementations **must** return UTC. Postgres stores absolute instants
 * (`TIMESTAMP WITH TIME ZONE`), so a local-zone implementation would not corrupt data, but it
 * would make every timestamp comparison in tests and logs depend on the container's TZ setting.
 *
 * Implementations **must** also return whole-second precision. Sub-second precision does not
 * survive being stored, so an instant carrying it would mean one thing in memory and another after
 * a reload — and timestamp equality would then depend on whether the instance happened to still be
 * cached rather than on the facts. Granularity is part of the contract for the same reason the
 * timezone is: a rule like "expires 24 hours after `issuedAt`", or a test comparing a recorded
 * timestamp against a frozen clock, is only sound if every implementation agrees on it. *Why* the
 * limit is one second is a storage detail and deliberately not stated here — see the adapter.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
