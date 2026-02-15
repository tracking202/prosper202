<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UserRoleRemoveCommand extends BaseCommand
{
    protected static $defaultName = 'user:role:remove';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Remove a role from a user')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('role_id', InputArgument::REQUIRED, 'Role ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user_id');
        $roleId = $input->getArgument('role_id');

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to remove role %s from user %s? [y/N] ', $roleId, $userId),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->render($output, $this->client()->delete('users/' . $userId . '/roles/' . $roleId), $input);
        return Command::SUCCESS;
    }
}
