<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\Fixture\RecordingSentryTransport;
use Sentry\Event;
use Sentry\Options;
use Sentry\State\HubInterface;

/**
 * Reads the events the SDK would have sent, from the transport the CLIENT actually holds — not a
 * second lookup by service id, which could name a different instance than the one the capture path
 * writes to. Call after the code under test, on the same booted kernel (`disableReboot()` for a
 * `KernelBrowser`): the transport keeps instance state per boot.
 *
 * For use in a `KernelTestCase`.
 */
trait ReadsSentryEvents
{
    private function sentryOptions(): Options
    {
        $hub = self::getContainer()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);
        $client = $hub->getClient();
        self::assertNotNull($client, 'The Sentry client must exist in test.');

        return $client->getOptions();
    }

    /** @return list<Event> */
    private function sentryEvents(): array
    {
        $transport = $this->sentryOptions()->getTransport();
        self::assertInstanceOf(RecordingSentryTransport::class, $transport);

        return $transport->events();
    }
}
