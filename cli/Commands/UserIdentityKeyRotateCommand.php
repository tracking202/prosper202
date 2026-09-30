<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Replace the identity linking key. Every cust_sig computed with the old key
 * stops linking at once, so it asks first, like every destructive command.
 */
class UserIdentityKeyRotateCommand extends BaseCommand
{
    protected static $defaultName = 'user:identity-key:rotate';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Replace the identity linking key; signatures made with the old key stop linking')
            ->addArgument('user_id', InputArgument::REQUIRED, 'User ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $userId = (string) $input->getArgument('user_id');
        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Rotate the identity linking key for user %s? Every cust_sig signed with the current key stops linking. [y/N] ', $userId),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->render($output, $this->client()->post('users/' . $userId . '/identity-key/rotate', []), $input);
        return Command::SUCCESS;
    }
}
