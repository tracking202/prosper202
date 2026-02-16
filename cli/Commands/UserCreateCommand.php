<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserCreateCommand extends BaseCommand
{
    protected static $defaultName = 'user:create';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create a new user')
            ->addOption('user_name', null, InputOption::VALUE_REQUIRED, 'Username (required)')
            ->addOption('user_email', null, InputOption::VALUE_REQUIRED, 'Email (required)')
            ->addOption('user_pass', null, InputOption::VALUE_OPTIONAL, 'Password (prompted securely if omitted)')
            ->addOption('user_fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('user_lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('user_timezone', null, InputOption::VALUE_REQUIRED, 'Timezone', 'UTC');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $required = ['user_name', 'user_email'];
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

        // Secure password input: if not provided via --user_pass, prompt interactively.
        // This avoids leaking the password into shell history and ps output.
        if (empty($body['user_pass'])) {
            $helper = $this->getHelper('question');
            $question = new Question('Password (hidden): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $helper->ask($input, $output, $question);
            if (!$password) {
                $output->writeln('<error>Password is required</error>');
                return Command::FAILURE;
            }
            $body['user_pass'] = $password;
        }

        $this->render($output, $this->client()->post('users', $body), $input);
        return Command::SUCCESS;
    }
}
