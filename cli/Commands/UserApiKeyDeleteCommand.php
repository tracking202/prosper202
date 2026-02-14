<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserApiKeyDeleteCommand extends Command
{
    protected static $defaultName = 'user:apikey:delete';
    protected function configure(): void
    {
        $this->setDescription('Delete an API key')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('api_key', InputArgument::REQUIRED, 'The API key to delete')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->delete('users/' . $input->getArgument('user_id') . '/api-keys/' . $input->getArgument('api_key')), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
