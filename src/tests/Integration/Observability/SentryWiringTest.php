<?php

declare(strict_types=1);

namespace App\Tests\Integration\Observability;

use App\Tests\Fixture\RecordingSentryTransport;
use App\Tests\Support\ReadsSentryEvents;
use Psr\Log\LoggerInterface;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ADR-0013 wiring, proven against the real container (AC-5, AC-6, AC-8, AC-14, AC-16). Events are
 * read from the recording transport of the SAME kernel the code under test ran in: the transport
 * holds instance state per boot, so a second `bootKernel()` would read an empty one.
 */
final class SentryWiringTest extends KernelTestCase
{
    use ReadsSentryEvents;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** AC-8, option half. */
    public function testPrivacyOptionsAreLockedDown(): void
    {
        $options = $this->sentryOptions();

        self::assertFalse($options->shouldSendDefaultPii());
        self::assertSame('never', $options->getMaxRequestBodySize());
        self::assertContains($options->getTracesSampleRate(), [null, 0.0]);
        self::assertNull($options->getProfilesSampleRate());
        self::assertFalse($options->getEnableLogs());
        self::assertNull($options->getLogger(), 'options.logger must stay unset: pointing the SDK at the app logger is a feedback loop.');
        self::assertSame('test', $options->getEnvironment());
    }

    /** AC-8, bundle half: the listeners that would make a second capture path are not registered. */
    public function testTheBundlesOwnCapturePathsAreSwitchedOff(): void
    {
        $container = self::getContainer();

        foreach (['ErrorListener', 'MessengerListener'] as $listener) {
            self::assertFalse($container->has('Sentry\\SentryBundle\\EventListener\\'.$listener), "$listener must not be registered.");
        }

        // `register_error_handler: false` filters the SDK's global PHP handlers out of the client's
        // integrations — the third capture path, invisible to the container.
        $hub = $container->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);
        $client = $hub->getClient();
        self::assertNotNull($client);
        foreach ([ErrorListenerIntegration::class, ExceptionListenerIntegration::class, FatalErrorListenerIntegration::class] as $integration) {
            self::assertNull($client->getIntegration($integration), "$integration must not be set up.");
        }
        self::assertFalse($container->getParameter('sentry.tracing.enabled'));
    }

    /** AC-14: both guards, so removing either one fails the build. */
    public function testTheTestEnvironmentCannotSend(): void
    {
        $options = $this->sentryOptions();

        $dsn = $options->getDsn();
        self::assertNotNull($dsn, 'The SDK must be enabled in test, or it records nothing.');
        self::assertStringEndsWith('.invalid', $dsn->getHost());
        self::assertInstanceOf(RecordingSentryTransport::class, $options->getTransport());
    }

    /** AC-16, option half. */
    public function testTheSdkTimeoutsBoundWhatASentryOutageCanAdd(): void
    {
        $options = $this->sentryOptions();

        self::assertLessThanOrEqual(1.0, $options->getHttpConnectTimeout());
        self::assertLessThanOrEqual(2.0, $options->getHttpTimeout());
    }

    public function testAnErrorOnAnyChannelProducesExactlyOneEventAtLevelError(): void
    {
        $this->logger('monolog.logger.pruning')->error('pruning run failed', ['reason' => 'db_down']);

        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertSame('error', (string) $events[0]->getLevel());
        self::assertSame('pruning run failed', $events[0]->getMessage());
    }

    /** AC-6 */
    public function testRecordsBelowErrorProduceNoEvent(): void
    {
        foreach (['monolog.logger.pruning', 'monolog.logger.messenger', 'logger'] as $id) {
            $logger = $this->logger($id);
            $logger->debug('d');
            $logger->info('Pruning run complete', ['deleted' => 3]);
            $logger->notice('n');
            $logger->warning('Error while handling message, retrying');
        }

        self::assertSame([], $this->sentryEvents());
    }

    /** AC-5 */
    public function testAnUncaughtExceptionInAConsoleCommandProducesExactlyOneEvent(): void
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.'));
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $application->addCommand(new class('test:boom') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('console boom');
            }
        });

        $exitCode = $application->run(new ArrayInput(['command' => 'test:boom']), new NullOutput());

        self::assertNotSame(0, $exitCode);
        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertSame(\RuntimeException::class, $events[0]->getExceptions()[0]->getType());
    }

    private function logger(string $id): LoggerInterface
    {
        $logger = self::getContainer()->get($id);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return $logger;
    }
}
