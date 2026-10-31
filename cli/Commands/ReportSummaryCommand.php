<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportSummaryCommand extends BaseCommand
{
    /** Filters shared by every report command, as the API names them (collectOptions() reads their options). */
    public const array FILTER_PARAMS = ['period', 'time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'ppc_network_id', 'landing_page_id', 'country_id'];

    protected static $defaultName = 'report:summary';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Get overall performance summary')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: ' . ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff-campaign-id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc-account-id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->collectOptions($input, self::FILTER_PARAMS);
        $result = $this->client()->get('reports/summary', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }

}
