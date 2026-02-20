<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportSummaryCommand extends BaseCommand
{
    protected static $defaultName = 'report:summary';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get overall performance summary')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = self::collectParams($input);
        $result = $this->client()->get('reports/summary', $params);
        $this->render($output, $result, $input);
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
