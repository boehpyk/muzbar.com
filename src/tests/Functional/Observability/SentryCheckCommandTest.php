<?php

declare(strict_types=1);

namespace App\Tests\Functional\Observability;

use App\Tests\Support\ReadsSentryEvents;
use Sentry\ClientBuilder;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AC-17: `muzbar:ops:sentry-check`, both branches. The enabled branch goes through the real
 * capture path (logger, Monolog handler, scrubber, recording transport) and asserts what would
 * have left the box.
 */
final class SentryCheckCommandTest extends KernelTestCase
{
    use ReadsSentryEvents;

    public function testWhenEnabledItRecordsExactlyOneScrubbedEventAndPrintsItsId(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $events = $this->sentryEvents();
        self::assertCount(1, $events);
        self::assertSame('error', (string) $events[0]->getLevel());
        self::assertStringContainsString((string) $events[0]->getId(), $tester->getDisplay());

        $serialized = serialize($events[0]);
        self::assertStringNotContainsString('CANARYTOKEN-sentry-check', $serialized);
        self::assertStringNotContainsString('canary.user@example.invalid', $serialized);

        $context = $events[0]->getExtra()['monolog.context'] ?? null;
        self::assertIsArray($context);
        self::assertSame('[Filtered]', $context['token'] ?? null, 'The `token` canary must be filtered by key.');
        self::assertSame('[email]', $context['note'] ?? null, 'The e-mail canary must be replaced by value.');
    }

    public function testWhenDisabledItSaysSoAndFails(): void
    {
        self::bootKernel();
        self::getContainer()->set(HubInterface::class, new Hub(ClientBuilder::create(['dsn' => null])->getClient()));
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Sentry is disabled (SENTRY_DSN is empty)', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('muzbar:ops:sentry-check'));
    }
}
