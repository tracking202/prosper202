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

class ReportSummaryCommand extends Command
{
    protected static $defaultName = 'report:summary';

    protected function configure(): void
    {
        $this->setDescription('Get overall performance summary')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = self::collectParams($input);
        $result = $client->get('reports/summary', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }

    public static function collectParams(InputInterface $input): array
    {
        $params = [];
        foreach (['period', 'time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $p) {
            if ($input->hasOption($p)) {
                $val = $input->getOption($p);
                if ($val !== null) {
                    $params[$p] = $val;
                }
            }
        }
        return $params;
    }
}
