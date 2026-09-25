<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/{id}/installs: an Android registration's installs, newest
 * first, with their match state, reason and trust. The Go CLI's
 * `p202 app install list`.
 */
class AppInstallListCommand extends BaseCommand
{
    protected static $defaultName = 'app:install:list';

    private const FILTERS = ['match_state', 'trusted', 'test', 'click_id', 'time_from', 'time_to'];

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List an Android registration\'s installs with their match state and reason')
            ->addArgument('registration_id', InputArgument::REQUIRED, 'The Android registration id (the Go CLI\'s `p202 app list --platform android` lists them)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('match_state', null, InputOption::VALUE_REQUIRED, 'Only this state: attributed, organic, third_party, unavailable, pending_click, bad_token, foreign_click, implausible, outside_window, duplicate_click, pending_integrity')
            ->addOption('trusted', null, InputOption::VALUE_REQUIRED, 'Only this trust class: trusted, refuted, unvouched')
            ->addOption('test', null, InputOption::VALUE_REQUIRED, '1 = only test installs, 0 = only real ones')
            ->addOption('click_id', null, InputOption::VALUE_REQUIRED, 'Only installs matched to this click')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Received-at range start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Received-at range end (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $registrationId = (string) $input->getArgument('registration_id');
        if (preg_match('/^[1-9][0-9]*$/D', $registrationId) !== 1) {
            throw new \RuntimeException('The registration id must be a positive whole number, got "' . $registrationId . '".');
        }
        $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];
        foreach (self::FILTERS as $filter) {
            $value = $input->getOption($filter);
            if ($value !== null) {
                $params[$filter] = $value;
            }
        }

        $this->render($output, $this->client()->get('apps/' . $registrationId . '/installs', $params), $input);
        return Command::SUCCESS;
    }
}
