<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OptimizeDaypartCommand extends BaseCommand
{
    protected static $defaultName = 'optimize:daypart';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Dayparting recommendations: which hours to increase/decrease bids based on ROI')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Days of history to analyze (7-90)', '30')
            ->addOption('target_roi', null, InputOption::VALUE_REQUIRED, 'Minimum acceptable ROI %', '0')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['lookback_days', 'target_roi', 'aff_campaign_id', 'ppc_account_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('optimize/daypart', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
