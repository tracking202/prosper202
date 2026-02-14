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

class UserApiKeyCreateCommand extends Command
{
    protected static $defaultName = 'user:apikey:create';
    protected function configure(): void
    {
        $this->setDescription('Generate a new API key for a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->post('users/' . $input->getArgument('user_id') . '/api-keys'), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
