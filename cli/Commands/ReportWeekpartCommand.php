<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReportWeekpartCommand extends BaseCommand
{
    protected static $defaultName = 'report:weekpart';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get performance by day of week')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: ' . ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort by: ' . ServerLists::list(ServerLists::WEEKPART_SORTS), 'day_of_week')
            ->addOption('sort-dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: ASC or DESC', 'ASC')
            ->addOption('aff-campaign-id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc-account-id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('aff-network-id', null, InputOption::VALUE_REQUIRED, 'Filter by affiliate network ID')
            ->addOption('ppc-network-id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID')
            ->addOption('landing-page-id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID')
            ->addOption('country-id', null, InputOption::VALUE_REQUIRED, 'Filter by country ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->collectOptions($input, ReportSummaryCommand::FILTER_PARAMS);
        $params['sort'] = (string)$input->getOption('sort');
        $params['sort_dir'] = (string)$input->getOption('sort-dir');

        $result = $this->client()->get('reports/weekpart', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
