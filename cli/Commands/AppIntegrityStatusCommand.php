<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/{id}/integrity: an Android registration's Play Integrity mode,
 * its service account (never the key), where its installs' verdicts stand
 * and today's decodes. The Go CLI's `p202 app integrity status`.
 */
class AppIntegrityStatusCommand extends BaseCommand
{
    protected static $defaultName = 'app:integrity:status';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show an Android app\'s Play Integrity mode, credential (never its key) and verdict counts')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AppIntegrityArgs::registrationId($input);
        $this->render($output, $this->client()->get('apps/' . $id . '/integrity'), $input);

        return Command::SUCCESS;
    }
}
