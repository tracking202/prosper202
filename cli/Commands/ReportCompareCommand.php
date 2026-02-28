<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReportCompareCommand extends BaseCommand
{
    protected static $defaultName = 'report:compare';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Compare two time periods side-by-side with change metrics')
            ->addOption('period_a_from', null, InputOption::VALUE_REQUIRED, 'Period A start (unix timestamp)')
            ->addOption('period_a_to', null, InputOption::VALUE_REQUIRED, 'Period A end (unix timestamp)')
            ->addOption('period_b_from', null, InputOption::VALUE_REQUIRED, 'Period B start (unix timestamp)')
            ->addOption('period_b_to', null, InputOption::VALUE_REQUIRED, 'Period B end (unix timestamp)')
            ->addOption('breakdown', 'b', InputOption::VALUE_REQUIRED, 'Breakdown dimension: campaign, aff_network, ppc_account, landing_page, keyword, country, etc.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results (with breakdown)', '50')
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
        foreach (['period_a_from', 'period_a_to', 'period_b_from', 'period_b_to'] as $required) {
            $val = $input->getOption($required);
            if ($val === null) {
                $output->writeln("<error>Missing required option: --$required</error>");
                return Command::FAILURE;
            }
            $params[$required] = $val;
        }

        foreach (['breakdown', 'limit', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('reports/compare', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
