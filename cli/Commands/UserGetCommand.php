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

class UserGetCommand extends Command
{
    protected static $defaultName = 'user:get';
    protected function configure(): void
    {
        $this->setDescription('Get user details with roles')
            ->addArgument('id', InputArgument::REQUIRED, 'User ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->get('users/' . $input->getArgument('id')), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
