<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportBreakdownCommand extends BaseCommand
{
    protected static $defaultName = 'report:breakdown';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get performance breakdown by dimension')
            ->addOption('breakdown', 'b', InputOption::VALUE_REQUIRED, 'Dimension: campaign, aff_network, ppc_account, ppc_network, landing_page, keyword, country, city, browser, platform, device, isp, text_ad', 'campaign')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort by: total_clicks, total_leads, total_income, total_cost, total_net, roi, epc, conv_rate', 'total_clicks')
            ->addOption('sort_dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: ASC or DESC', 'DESC')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
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
        $params['breakdown'] = $input->getOption('breakdown');
        $params['sort'] = $input->getOption('sort');
        $params['sort_dir'] = $input->getOption('sort_dir');
        $params['limit'] = $input->getOption('limit');
        $params['offset'] = $input->getOption('offset');

        $result = $this->client()->get('reports/breakdown', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
