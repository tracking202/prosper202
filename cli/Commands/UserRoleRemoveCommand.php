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

class UserRoleRemoveCommand extends Command
{
    protected static $defaultName = 'user:role:remove';
    protected function configure(): void
    {
        $this->setDescription('Remove a role from a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('role_id', InputArgument::REQUIRED, 'Role ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->delete('users/' . $input->getArgument('user_id') . '/roles/' . $input->getArgument('role_id')), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
