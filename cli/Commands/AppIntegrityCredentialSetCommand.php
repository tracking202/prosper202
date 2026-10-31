<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * PUT /apps/{id}/integrity-credential: set or rotate the Google service
 * account Play Integrity decodes with. The key is read from a file — never
 * a command-line value, which shell history and process listings keep — and
 * the server stores it encrypted and answers with the account's email and
 * key id only. The Go CLI's `p202 app integrity credential set`.
 */
class AppIntegrityCredentialSetCommand extends BaseCommand
{
    protected static $defaultName = 'app:integrity:credential:set';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Set or rotate an Android app\'s Play Integrity service account (stored encrypted, never shown)')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'The service-account key file (JSON)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AppIntegrityArgs::registrationId($input);
        $key = AppIntegrityArgs::keyFile((string) $input->getOption('file'));
        $this->render($output, $this->client()->put('apps/' . $id . '/integrity-credential', ['credential' => $key]), $input);

        return Command::SUCCESS;
    }
}
