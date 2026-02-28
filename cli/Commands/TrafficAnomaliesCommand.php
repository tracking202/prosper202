<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TrafficAnomaliesCommand extends BaseCommand
{
    protected static $defaultName = 'traffic:anomalies';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Detect anomalies in metrics using statistical deviation from rolling average')
            ->addOption('metric', 'm', InputOption::VALUE_REQUIRED, 'Metric: total_cost, total_clicks, total_leads, total_income, total_net, conv_rate, epc, roi', 'total_cost')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Z-score threshold for anomaly detection (1.0-5.0)', '2.0')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Days of history to analyze (7-90)', '30')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Granularity: hour, day', 'day')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['metric', 'threshold', 'lookback_days', 'interval', 'aff_campaign_id', 'ppc_account_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('traffic/anomalies', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
