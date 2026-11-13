<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Observability;

use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator's proof that an error actually reaches Sentry (ADR-0013, Decision 7). Run it in
 * `app` AND `messenger-worker` after any deploy that touches config: each container builds its own
 * kernel from its own environment.
 *
 * WHY NOT THE BUNDLE'S `sentry:test`. That calls `captureMessage()` directly, so it proves neither
 * the Monolog handler nor the scrubber — the two things most likely to be wrong. This command logs
 * one `error` through the application logger, i.e. the real capture path, carrying canaries that
 * must arrive as `[Filtered]` (by key) and `[email]` (by value).
 *
 * The hub is injected, never `SentrySdk::getCurrentHub()`: the static would leak state across tests
 * and could differ from the hub the Monolog handler holds.
 */
#[AsCommand(
    name: 'muzbar:ops:sentry-check',
    description: 'Log one canary error through the real capture path and print the Sentry event id',
)]
final class SentryCheckCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HubInterface $hub,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $this->hub->getClient();

        if (null === $client || null === $client->getOptions()->getDsn()) {
            $io->error('Sentry is disabled (SENTRY_DSN is empty)');

            return Command::FAILURE;
        }

        $this->logger->error('Sentry check: canary error from muzbar:ops:sentry-check', [
            'token' => 'CANARYTOKEN-sentry-check',
            'note' => 'canary.user@example.invalid',
        ]);
        // Sends are synchronous, but flush anyway so the command never exits before delivery.
        $client->flush();

        $eventId = $this->hub->getLastEventId();
        if (null === $eventId) {
            $io->error('No Sentry event id was recorded — the capture path did not produce an event.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Sentry event id: %s', (string) $eventId));

        return Command::SUCCESS;
    }
}
