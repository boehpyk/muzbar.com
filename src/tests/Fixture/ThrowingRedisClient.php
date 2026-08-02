<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Predis\ClientInterface as RedisClient;
use Predis\Command\CommandInterface;
use Predis\Command\FactoryInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Response\Status;

/**
 * A test double for `Predis\ClientInterface` that fails exactly one key's `set()`/`del()` call and
 * answers every other call as if nothing were wrong.
 *
 * WHY ONE CONFIGURABLE DOUBLE RATHER THAN A DOUBLE THAT FAILS EVERYTHING. `PruneChallengesCommand`
 * makes exactly three Redis calls in a normal run: `set()` for the run lock (`LOCK_KEY`, with `NX`),
 * `set()` for the heartbeat (`HEARTBEAT_KEY`, no `NX`), and `del()` to release the lock. Two of this
 * slice's four untested log paths (`lock_unavailable` and `heartbeat_failed`) each need Redis to fail
 * on exactly *one* of those calls while the rest of the run proceeds normally — proving AC-16's
 * fail-open half means proving the run **continues** past the failure, which a double that fails
 * every call cannot show. Matching on the key rather than the method name is what keeps the two
 * scenarios independent: a lock `set()` failure must not also break the heartbeat `set()`, and the
 * reverse, or a test built to isolate one branch would silently exercise both.
 *
 * Any other Redis call is a call this command does not make. Reaching one would mean the command's
 * Redis usage grew a dependency this double was not told about, so it throws rather than returning a
 * silently plausible value a real bug could hide behind.
 */
final class ThrowingRedisClient implements RedisClient
{
    public function __construct(
        private readonly string $failingKey,
    ) {
    }

    public function getCommandFactory(): FactoryInterface
    {
        throw new \LogicException('Not used by PruneChallengesCommand (test double).');
    }

    public function getOptions(): OptionsInterface
    {
        throw new \LogicException('Not used by PruneChallengesCommand (test double).');
    }

    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function getConnection(): ConnectionInterface
    {
        throw new \LogicException('Not used by PruneChallengesCommand (test double).');
    }

    /**
     * @param array<mixed> $arguments
     */
    public function createCommand($method, $arguments = []): CommandInterface
    {
        throw new \LogicException('Not used by PruneChallengesCommand (test double).');
    }

    public function executeCommand(CommandInterface $command): mixed
    {
        throw new \LogicException('Not used by PruneChallengesCommand (test double).');
    }

    /**
     * @param array<mixed> $arguments
     */
    public function __call($method, $arguments): mixed
    {
        $name = strtolower((string) $method);

        $targetsFailingKey = match ($name) {
            // The lock `set()` — `set(LOCK_KEY, $value, 'EX', $ttl, 'NX')` — and the heartbeat
            // `set()` — `set(HEARTBEAT_KEY, $value)` — both carry the key as the first argument.
            'set' => ($arguments[0] ?? null) === $this->failingKey,
            // `del([LOCK_KEY])` carries the key inside a one-element array.
            'del' => \in_array($this->failingKey, (array) ($arguments[0] ?? []), true),
            default => false,
        };

        if ($targetsFailingKey) {
            throw new \RuntimeException(\sprintf('Simulated Redis failure on %s(%s) (test double).', $method, $this->failingKey));
        }

        return match ($name) {
            'set' => Status::get('OK'),
            'del' => 1,
            default => throw new \LogicException(\sprintf('Unexpected Redis call: %s (test double).', $method)),
        };
    }
}
