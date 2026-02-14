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

class UserUpdateCommand extends Command
{
    protected static $defaultName = 'user:update';
    protected function configure(): void
    {
        $this->setDescription('Update a user')
            ->addArgument('id', InputArgument::REQUIRED, 'User ID')
            ->addOption('user_fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('user_lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('user_email', null, InputOption::VALUE_REQUIRED, 'Email')
            ->addOption('user_pass', null, InputOption::VALUE_REQUIRED, 'New password')
            ->addOption('user_timezone', null, InputOption::VALUE_REQUIRED, 'Timezone')
            ->addOption('user_active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [];
        foreach (['user_fname', 'user_lname', 'user_email', 'user_pass', 'user_timezone', 'user_active'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }
        if (empty($body)) {
            $output->writeln('<error>Provide at least one field</error>');
            return Command::FAILURE;
        }
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->put('users/' . $input->getArgument('id'), $body), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
