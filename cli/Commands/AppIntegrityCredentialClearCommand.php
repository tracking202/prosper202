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
 * DELETE /apps/{id}/integrity-credential: delete the service account. The
 * server refuses it (409) while the mode is observe or require, or while any
 * install of the app is still waiting for a verdict. Confirms
 * unless --force, like every delete here. The Go CLI's
 * `p202 app integrity credential clear`.
 */
class AppIntegrityCredentialClearCommand extends BaseCommand
{
    protected static $defaultName = 'app:integrity:credential:clear';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete an Android app\'s Play Integrity service account (refused unless the mode is off and no install awaits a verdict)')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AppIntegrityArgs::registrationId($input);
        if (!$input->getOption('force')) {
            $question = new ConfirmationQuestion(sprintf('Delete the Play Integrity credential of registration %s? [y/N] ', $id), false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');

                return Command::SUCCESS;
            }
        }
        $result = $this->client()->delete('apps/' . $id . '/integrity-credential');
        if ($this->isJson($input)) {
            $this->render($output, $result, $input);
        } else {
            // A void operation says what happened (CLAUDE.md #6).
            $output->writeln('<info>' . (string) ($result['data']['message'] ?? 'Done.') . '</info>');
        }

        return Command::SUCCESS;
    }
}
