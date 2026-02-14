<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserCreateCommand extends Command
{
    protected static $defaultName = 'user:create';
    protected function configure(): void
    {
        $this->setDescription('Create a new user')
            ->addOption('user_name', null, InputOption::VALUE_REQUIRED, 'Username (required)')
            ->addOption('user_email', null, InputOption::VALUE_REQUIRED, 'Email (required)')
            ->addOption('user_pass', null, InputOption::VALUE_REQUIRED, 'Password (required)')
            ->addOption('user_fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('user_lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('user_timezone', null, InputOption::VALUE_REQUIRED, 'Timezone', 'UTC')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $required = ['user_name', 'user_email', 'user_pass'];
        $body = [];
        foreach (['user_name', 'user_email', 'user_pass', 'user_fname', 'user_lname', 'user_timezone'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }
        foreach ($required as $r) {
            if (empty($body[$r])) {
                $output->writeln("<error>--$r is required</error>");
                return Command::FAILURE;
            }
        }
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->post('users', $body), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
