<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserApiKeyDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'user:apikey:delete';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete an API key')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('api_key', InputArgument::REQUIRED, 'The API key to delete')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user_id');
        $apiKey = $input->getArgument('api_key');

        if (!$this->confirmDestructive($input, $output, sprintf('delete API key %s for user %s', $apiKey, $userId))) {
            return Command::SUCCESS;
        }

        $this->client()->delete('users/' . $userId . '/api-keys/' . $apiKey);
        $output->writeln('<info>API key deleted.</info>');
        return Command::SUCCESS;
    }
}
