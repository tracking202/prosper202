<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UserApiKeyDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'user:apikey:delete';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete an API key')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addArgument('api_key', InputArgument::REQUIRED, 'The API key to delete')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user_id');
        $apiKey = $input->getArgument('api_key');

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to delete API key %s for user %s? [y/N] ', $apiKey, $userId),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->render($output, $this->client()->delete('users/' . $userId . '/api-keys/' . $apiKey), $input);
        return Command::SUCCESS;
    }
}
