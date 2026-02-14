<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportTimeseriesCommand extends Command
{
    protected static $defaultName = 'report:timeseries';

    protected function configure(): void
    {
        $this->setDescription('Get performance over time')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Interval: hour, day, week, month', 'day')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('aff_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by affiliate network ID')
            ->addOption('ppc_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID')
            ->addOption('landing_page_id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page ID')
            ->addOption('country_id', null, InputOption::VALUE_REQUIRED, 'Filter by country ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = ReportSummaryCommand::collectParams($input);
        $params['interval'] = $input->getOption('interval');

        $result = $client->get('reports/timeseries', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
