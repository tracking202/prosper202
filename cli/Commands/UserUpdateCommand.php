<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserUpdateCommand extends BaseCommand
{
    protected static $defaultName = 'user:update';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Update a user')
            ->addArgument('id', InputArgument::REQUIRED, 'User ID')
            ->addOption('user_fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('user_lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('user_email', null, InputOption::VALUE_REQUIRED, 'Email')
            ->addOption('user_pass', null, InputOption::VALUE_OPTIONAL, 'New password (prompted securely if flag given without value)')
            ->addOption('user_timezone', null, InputOption::VALUE_REQUIRED, 'Timezone')
            ->addOption('user_active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = $this->collectOptions($input, ['user_fname', 'user_lname', 'user_email', 'user_timezone', 'user_active']);

        // Handle password separately — prompt securely if --user_pass given without value
        $passVal = $input->getOption('user_pass');
        if ($passVal === null && $input->hasParameterOption('--user_pass')) {
            $passVal = $this->promptHiddenSecret($input, $output, 'New password (hidden): ');
        }
        if (is_string($passVal) && $passVal !== '') {
            $body['user_pass'] = $passVal;
        }
        if (empty($body)) {
            $output->writeln('<error>Provide at least one field</error>');
            return Command::FAILURE;
        }
        $this->render($output, $this->client()->put('users/' . $input->getArgument('id'), $body), $input);
        return Command::SUCCESS;
    }
}
