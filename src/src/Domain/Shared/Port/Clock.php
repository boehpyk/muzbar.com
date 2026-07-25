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
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
