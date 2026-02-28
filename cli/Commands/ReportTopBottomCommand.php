<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportTopBottomCommand extends BaseCommand
{
    protected static $defaultName = 'report:top-bottom';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show top N and bottom N performers by any metric')
            ->addOption('breakdown', 'b', InputOption::VALUE_REQUIRED, 'Dimension: campaign, aff_network, ppc_account, landing_page, keyword, country, etc.', 'campaign')
            ->addOption('metric', 'm', InputOption::VALUE_REQUIRED, 'Metric to rank by: total_net, roi, epc, conv_rate, total_clicks, total_leads, total_income, total_cost, cpa', 'total_net')
            ->addOption('n', null, InputOption::VALUE_REQUIRED, 'Number of top/bottom to show', '10')
            ->addOption('min_clicks', null, InputOption::VALUE_REQUIRED, 'Minimum clicks to include', '0')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('aff_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by affiliate network ID')
            ->addOption('ppc_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID')
            ->addOption('landing_page_id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID')
            ->addOption('country_id', null, InputOption::VALUE_REQUIRED, 'Filter by country ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['breakdown', 'metric', 'n', 'min_clicks', 'period', 'time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('reports/top-bottom', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
