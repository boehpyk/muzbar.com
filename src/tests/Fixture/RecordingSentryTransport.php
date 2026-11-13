<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * The Sentry transport under `APP_ENV=test` (ADR-0013, Decision 7): records every event the SDK
 * would have sent — after `before_send`, so what a test reads is exactly what would have left the
 * box — and opens no socket.
 *
 * One of two guards, deliberately. `.env.test` also points the DSN at a `.invalid` host (RFC 2606,
 * cannot resolve); either alone prevents a send, and a test asserts both so removing one fails.
 *
 * Instance state, not static: each kernel boot builds a fresh transport and starts empty, so no
 * event leaks from one test into the next. Fetch it from the container *after* the request or
 * command under test, within the same kernel (`disableReboot()` for a `KernelBrowser`).
 */
final class RecordingSentryTransport implements TransportInterface
{
    /** @var list<Event> */
    private array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->events;
    }
}
