<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReportWeekpartCommand extends BaseCommand
{
    protected static $defaultName = 'report:weekpart';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get performance by day of week')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort by: day_of_week, total_clicks, total_click_throughs, total_leads, total_income, total_cost, total_net, epc, avg_cpc, conv_rate, roi, cpa', 'day_of_week')
            ->addOption('sort_dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: ASC or DESC', 'ASC')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('aff_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by affiliate network ID')
            ->addOption('ppc_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID')
            ->addOption('landing_page_id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID')
            ->addOption('country_id', null, InputOption::VALUE_REQUIRED, 'Filter by country ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = ReportSummaryCommand::collectParams($input);
        $params['sort'] = (string)$input->getOption('sort');
        $params['sort_dir'] = (string)$input->getOption('sort_dir');

        $result = $this->client()->get('reports/weekpart', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
