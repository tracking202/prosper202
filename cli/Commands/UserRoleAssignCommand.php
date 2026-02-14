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

class UserRoleAssignCommand extends Command
{
    protected static $defaultName = 'user:role:assign';
    protected function configure(): void
    {
        $this->setDescription('Assign a role to a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption('role_id', null, InputOption::VALUE_REQUIRED, 'Role ID (required)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roleId = $input->getOption('role_id');
        if (!$roleId) {
            $output->writeln('<error>--role_id is required</error>');
            return Command::FAILURE;
        }
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->post('users/' . $input->getArgument('user_id') . '/roles', ['role_id' => (int)$roleId]), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
