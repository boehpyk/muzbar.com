<?php

declare(strict_types=1);

namespace App\Tests\Integration\Observability;

use App\Tests\Support\ReadsSentryEvents;
use Sentry\Event;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * AC-4 and AC-12, through Symfony's real `SendFailedMessageForRetryListener` and the real event
 * dispatcher: no custom subscriber exists (ADR-0013 decision 5), so what is under test is that
 * Messenger's own log levels (warning on a retry, critical on exhaustion) meet the Monolog
 * handler's ERROR threshold in the right place. The retry limit is read from the configured retry
 * strategy, never hard-coded; the retry path writes to the `async` doctrine transport and the
 * exhausted path to `failed`, both rolled back by DAMA.
 */
final class MessengerFailureReportingTest extends KernelTestCase
{
    use ReadsSentryEvents;

    private const string CANARY_ADDRESS = 'canary.victim@example.invalid';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAFailureThatWillBeRetriedProducesNoEvent(): void
    {
        $this->failMessage(new Envelope($this->message()));

        self::assertSame([], $this->sentryEvents());
    }

    public function testTheLastRetryBeforeExhaustionStillProducesNoEvent(): void
    {
        $this->failMessage(new Envelope($this->message(), [new RedeliveryStamp($this->maxRetries() - 1)]));

        self::assertSame([], $this->sentryEvents());
    }

    public function testAFinalFailureProducesExactlyOneEventNamingTheMessageClassAndTheHandlersException(): void
    {
        $this->failMessage(new Envelope($this->message(), [new RedeliveryStamp($this->maxRetries())]));

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        // The class is in the log CONTEXT (`{class}`), not interpolated into the message: the Sentry
        // handler has no PSR-3 message processor, so `getMessage()` is Messenger's raw template.
        $context = $events[0]->getExtra()['monolog.context'] ?? null;
        self::assertIsArray($context);
        self::assertSame(SendEmailMessage::class, $context['class'] ?? null);
        self::assertContains(TransportException::class, $this->exceptionTypes($events[0]));
    }

    /**
     * AC-12: the message stays Messenger's raw template (no PSR-3 interpolation), so the SMTP text
     * reaches the event via `monolog.context['error']` and the exception value — the e-mail regex
     * is what catches it there.
     */
    public function testTheSmtpRejectionAddressIsScrubbedFromTheExhaustedEvent(): void
    {
        $envelope = new Envelope($this->message(), [new RedeliveryStamp($this->maxRetries())]);
        $this->failMessage($envelope);

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertStringNotContainsString(self::CANARY_ADDRESS, serialize($events[0]));
        self::assertStringContainsString('<[email]>: Recipient address rejected', serialize($events[0]), 'The address must be replaced in place, not merely dropped.');
    }

    /** The canary really is in what Messenger logs — otherwise the scrub test above proves nothing. */
    public function testTheFixtureExceptionCarriesTheCanaryAddress(): void
    {
        self::assertStringContainsString(self::CANARY_ADDRESS, $this->exception()->getMessage());
        self::assertStringContainsString(self::CANARY_ADDRESS, $this->exception()->getWrappedExceptions()[0]->getMessage());
    }

    private function receiver(): string
    {
        return 'async';
    }

    private function message(): SendEmailMessage
    {
        return new SendEmailMessage(new RawMessage('raw'));
    }

    private function exception(): HandlerFailedException
    {
        $envelope = new Envelope($this->message());

        return new HandlerFailedException($envelope, [
            new TransportException(\sprintf('Expected response code "250" but got code "550", with message "550 <%s>: Recipient address rejected".', self::CANARY_ADDRESS)),
        ]);
    }

    private function failMessage(Envelope $envelope): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, $this->receiver(), $this->exception()));
    }

    /** Smallest retry count the configured `async` strategy refuses to retry. */
    private function maxRetries(): int
    {
        $locator = self::getContainer()->get('messenger.retry_strategy_locator');
        self::assertInstanceOf(ServiceProviderInterface::class, $locator);
        $strategy = $locator->get($this->receiver());
        self::assertInstanceOf(RetryStrategyInterface::class, $strategy);

        for ($count = 0; $count < 100; ++$count) {
            if (!$strategy->isRetryable(new Envelope($this->message(), [new RedeliveryStamp($count)]))) {
                return $count;
            }
        }

        self::fail('The async retry strategy never gives up.');
    }

    /** @return list<string> */
    private function exceptionTypes(Event $event): array
    {
        return array_values(array_map(static fn ($bag): string => $bag->getType(), $event->getExceptions()));
    }
}
