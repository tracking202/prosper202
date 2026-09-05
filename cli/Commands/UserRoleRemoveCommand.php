<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserRoleRemoveCommand extends BaseCommand
{
    protected static $defaultName = 'user:role:remove';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Remove a role from a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('role_id', InputArgument::REQUIRED, 'Role ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user_id');
        $roleId = $input->getArgument('role_id');

        if (!$this->confirmDestructive($input, $output, sprintf('remove role %s from user %s', $roleId, $userId))) {
            return Command::SUCCESS;
        }

        $this->client()->delete('users/' . $userId . '/roles/' . $roleId);
        $output->writeln(sprintf('<info>Removed role #%s from user #%s.</info>', $roleId, $userId));
        return Command::SUCCESS;
    }
}
