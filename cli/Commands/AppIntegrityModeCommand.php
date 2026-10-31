<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * PUT /apps/{id} with integrity_mode (and optionally the Cloud project
 * number): switch Play Integrity off, observe or require. The Go CLI's
 * `p202 app update <id> --integrity-mode … --integrity-cloud-project-number …`.
 * observe and require need the credential first (app:integrity:credential:set)
 * and a Cloud project number (this option, or one already set); the number
 * can be replaced, not cleared.
 */
class AppIntegrityModeCommand extends BaseCommand
{
    protected static $defaultName = 'app:integrity:mode';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Set an Android app\'s Play Integrity mode: off, observe or require')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration')
            ->addArgument('mode', InputArgument::REQUIRED, 'off, observe (record verdicts) or require (attribute only a passing verdict)')
            ->addOption('cloud-project-number', null, InputOption::VALUE_REQUIRED, 'The Google Cloud project NUMBER the SDK requests tokens for (required for observe/require unless already set)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AppIntegrityArgs::registrationId($input);
        $mode = (string) $input->getArgument('mode');
        if (!in_array($mode, AppIntegrityArgs::MODES, true)) {
            throw new \RuntimeException('The mode must be one of: ' . implode(', ', AppIntegrityArgs::MODES) . '; got "' . $mode . '".');
        }
        $body = ['integrity_mode' => $mode];
        $project = $input->getOption('cloud-project-number');
        if ($project !== null) {
            if (!is_string($project) || preg_match('/^[1-9][0-9]{0,17}$/D', $project) !== 1) {
                throw new \RuntimeException('--cloud-project-number must be the Google Cloud project NUMBER (digits, on the project dashboard), not its id.');
            }
            $body['integrity_cloud_project_number'] = $project;
        }
        $this->render($output, $this->client()->put('apps/' . $id, $body), $input);

        return Command::SUCCESS;
    }
}
