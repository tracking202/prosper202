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
            ->addOption('user-fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('user-lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('user-email', null, InputOption::VALUE_REQUIRED, 'Email')
            ->addOption('user-pass', null, InputOption::VALUE_OPTIONAL, 'New password (prompted securely if flag given without value)')
            ->addOption('user-timezone', null, InputOption::VALUE_REQUIRED, 'Timezone')
            ->addOption('user-active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $body = $this->collectOptions($input, ['user_fname', 'user_lname', 'user_email', 'user_timezone', 'user_active']);

        // Handle password separately — prompt securely if --user-pass given without value
        $passVal = $input->getOption('user-pass');
        if ($passVal === null && $input->hasParameterOption('--user-pass')) {
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
