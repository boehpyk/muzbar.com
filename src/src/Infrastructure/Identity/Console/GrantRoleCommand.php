<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Console;

use App\Application\Identity\Command\GrantRole;
use App\Application\Identity\Command\RevokeRole;
use App\Application\Identity\Handler\GrantRoleHandler;
use App\Application\Identity\Handler\RevokeRoleHandler;
use App\Domain\Identity\Exception\CannotRevokeBaseRole;
use App\Domain\Identity\Exception\InvalidEmail;
use App\Domain\Identity\Exception\UserNotFound;
use App\Domain\Identity\ValueObject\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way a role is granted or revoked (ADR-0005 amendment, plan D10): an operator at the
 * console. There is deliberately no web UI — an admin who can mint admins needs its own threat
 * model. A revoked admin loses access on their next request, because Symfony's user refresh sees
 * the changed roles (AC-32).
 *
 * Exit 0 on success, including a grant of a role already held (I-3: idempotent). Exit 1 with the
 * AC-31 message for an unknown email, an unknown role or `--revoke ROLE_USER`; nothing changes in
 * any of those. Nothing is logged: the operator typed the email and reads the answer here.
 */
#[AsCommand(
    name: 'muzbar:identity:grant-role',
    description: 'Grants a role to a user, or revokes it with --revoke.',
)]
final class GrantRoleCommand extends Command
{
    public function __construct(
        private readonly GrantRoleHandler $grantRole,
        private readonly RevokeRoleHandler $revokeRole,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The account\'s email address')
            ->addArgument('role', InputArgument::REQUIRED, \sprintf('One of: %s', $this->validRoles()))
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke the role instead of granting it')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $role = $input->getArgument('role');
        $revoke = (bool) $input->getOption('revoke');
        \assert(\is_string($email) && \is_string($role));

        // Checked here rather than by catching the handler's `\ValueError`: a `\ValueError` from
        // anywhere deeper would otherwise be reported as "unknown role".
        if (null === Role::tryFrom($role)) {
            $io->error(\sprintf('Unknown role "%s". Valid roles: %s', $role, $this->validRoles()));

            return Command::FAILURE;
        }

        try {
            if ($revoke) {
                ($this->revokeRole)(new RevokeRole($email, $role));
            } else {
                ($this->grantRole)(new GrantRole($email, $role));
            }
        } catch (UserNotFound|InvalidEmail) {
            // A malformed address can match no row, so it gets the same answer as an unknown one.
            $io->error(\sprintf('No user found with email "%s"', $email));

            return Command::FAILURE;
        } catch (CannotRevokeBaseRole $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf($revoke ? 'Revoked %s from "%s".' : 'Granted %s to "%s".', $role, $email));

        return Command::SUCCESS;
    }

    private function validRoles(): string
    {
        return implode(', ', array_map(static fn (Role $role): string => $role->value, Role::cases()));
    }
}
