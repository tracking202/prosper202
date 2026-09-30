<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/notifications: the traffic-source postbacks app installs'
 * goals queued, with where each stands and a summary by status. The Go
 * CLI's `p202 app notifications`.
 */
class AppNotificationsCommand extends BaseCommand
{
    protected static $defaultName = 'app:notifications';

    private const FILTERS = ['registration_id', 'status', 'kind', 'time_from', 'time_to'];

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List the traffic-source postbacks app installs\' goals queued: sent, failed, pending, cancelled, suppressed')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('registration_id', null, InputOption::VALUE_REQUIRED, 'Only this app\'s postbacks')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'pending, sent, failed, cancelled or suppressed')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'reached, correction or retraction')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Queued at or after (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Queued at or before (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];
        foreach (self::FILTERS as $filter) {
            $value = $input->getOption($filter);
            if ($value !== null && $value !== '') {
                $params[$filter] = $value;
            }
        }
        $this->render($output, $this->client()->get('apps/notifications', $params), $input);

        return Command::SUCCESS;
    }
}
