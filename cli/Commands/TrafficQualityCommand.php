<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TrafficQualityCommand extends BaseCommand
{
    protected static $defaultName = 'traffic:quality';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Traffic quality scorecard: bot rates, filtered rates, conversion quality by dimension')
            ->addOption('breakdown', 'b', InputOption::VALUE_REQUIRED, 'Dimension: campaign, ppc_account, landing_page, country, isp, device, browser, platform', 'campaign')
            ->addOption('min_clicks', null, InputOption::VALUE_REQUIRED, 'Minimum clicks to include', '10')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('landing_page_id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['breakdown', 'min_clicks', 'limit', 'period', 'time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'landing_page_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('traffic/quality', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
