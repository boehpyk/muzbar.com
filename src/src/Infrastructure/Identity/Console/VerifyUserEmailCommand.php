<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Console;

use App\Application\Identity\Command\VerifyUserEmail;
use App\Application\Identity\Handler\VerifyUserEmailHandler;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\Port\UserRepository;
use App\Domain\Identity\ValueObject\Email;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The slice-1 caller of `VerifyUserEmail`, and the operator's escape hatch until
 * `identity-email-verification` ships the self-service flow.
 *
 * It is worth twenty lines for a reason beyond operations: it makes the verification use case a
 * *used* one rather than speculative code written for a future slice. When that slice arrives it
 * adds a signed-link controller as a **second adapter over this same handler** and deletes nothing
 * — one use case, two adapters, which is hexagonal architecture demonstrating itself rather than
 * being asserted in a comment.
 */
#[AsCommand(
    name: 'muzbar:identity:verify-email',
    description: 'Marks a user\'s email address as verified.',
)]
final class VerifyUserEmailCommand extends Command
{
    public function __construct(
        private readonly VerifyUserEmailHandler $verifyUserEmail,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The email address to mark as verified');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $address = $input->getArgument('email');

        // `getArgument()` is typed `mixed` because an argument may be declared as an array. This
        // one is `REQUIRED` and scalar, so Console guarantees a string — the assertion is how that
        // guarantee reaches the analyser rather than a cast that would paper over a real bug.
        \assert(\is_string($address));

        try {
            // WHY THE REPOSITORY IS CONSULTED *BEFORE* THE HANDLER. `User::verifyEmail()` is
            // idempotent and the handler is deliberately branch-free — neither of them can tell
            // the caller whether anything actually changed, and neither of them should: "was this
            // already true?" is a reporting question, not a business rule. Asking first is the
            // only honest way to print a distinguishable message, and it costs one indexed lookup
            // on a command a human runs by hand.
            $email = Email::fromString($address);
            $wasAlreadyVerified = $this->users->findByEmail($email)?->isEmailVerified() ?? false;

            ($this->verifyUserEmail)(new VerifyUserEmail($address));
        } catch (UserNotFound) {
            // The message is spelled out here rather than echoing `$e->getMessage()` so the exact
            // operator-facing string stays under this file's control (the failure contract pins
            // it). Nothing has been mutated: the handler throws before it touches the aggregate.
            $io->error(\sprintf('No user found with email "%s"', $address));

            return Command::FAILURE;
        } catch (InvalidEmail $e) {
            // A malformed address can never match a row, so this is the same outcome as "unknown
            // email" with a more useful sentence. Failing here also means the `Email` value object
            // is the single arbiter of what an address is — the command does no parsing of its own.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($wasAlreadyVerified) {
            // Exit 0, because idempotent success is success: a second run leaves the timestamp
            // untouched and records no event (AC-25). Anything else would make this command unsafe
            // to put in a script.
            $io->success(\sprintf('"%s" was already verified; nothing changed.', $email->toString()));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('"%s" is now verified.', $email->toString()));

        return Command::SUCCESS;
    }
}
