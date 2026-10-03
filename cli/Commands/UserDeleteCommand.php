<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'user:delete';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Soft-delete a user')
            ->addArgument('id', InputArgument::REQUIRED, 'User ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        if (!$this->confirmDestructive($input, $output, sprintf('delete user %s', $id))) {
            return Command::SUCCESS;
        }

        $this->client()->delete('users/' . $id);
        $output->writeln(sprintf('<info>Deleted user #%s.</info>', $id));
        return Command::SUCCESS;
    }
}
