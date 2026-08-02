<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Psr\Log\AbstractLogger;

/**
 * A test double for `Psr\Log\LoggerInterface` that records every call instead of writing to Monolog's
 * configured handlers.
 *
 * `PasswordResetController::request()`'s `MailTransportFailure|QueueTransportFailure` catch is the
 * *only* signal an operator gets when the reset flow's mail delivery silently fails — the HTTP
 * response stays the same neutral 302 as every other case, by design (AC-6). The catch's own docblock
 * argues at length for `error`, not `info`, and for omitting the submitted address from the context;
 * neither claim was ever asserted, so a regression downgrading the level or adding the address back in
 * would stay green. This double is what lets a test tell the two apart — a plain string search over
 * rendered HTML cannot, since the log line never reaches the response.
 *
 * Bound over the concrete `monolog.logger` service id (the "app" channel), never a
 * `Psr\Log\LoggerInterface` alias — the same rule CLAUDE.md documents for swapping any adapter inside a
 * test: `ResolveReferencesToAliasesPass` has already rewritten the controller's constructor argument to
 * the concrete id at compile time.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * Every record written through this double, in call order.
     *
     * `last()` is enough for a test that cares only about the final line; a test proving that one
     * *specific* record exists somewhere among several — e.g. a secondary warning logged alongside
     * the run's own completion line — needs the whole sequence rather than just its tail.
     *
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * The first record whose message matches `$message`, or `null` if none does.
     *
     * @return array{level: mixed, message: string, context: array<string, mixed>}|null
     */
    public function find(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($message === $record['message']) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{level: mixed, message: string, context: array<string, mixed>}
     */
    public function last(): array
    {
        $last = $this->records[\count($this->records) - 1] ?? null;

        if (null === $last) {
            throw new \LogicException('No log record was ever written through this double.');
        }

        return $last;
    }
}
