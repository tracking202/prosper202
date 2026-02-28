<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OptimizeGeoCommand extends BaseCommand
{
    protected static $defaultName = 'optimize:geo';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Geo targeting recommendations: which countries/regions to scale, maintain, optimize, or exclude')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Geographic level: country, region, city', 'country')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Days of history to analyze (7-90)', '30')
            ->addOption('min_clicks', null, InputOption::VALUE_REQUIRED, 'Minimum clicks for inclusion', '20')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['level', 'lookback_days', 'min_clicks', 'limit', 'aff_campaign_id', 'ppc_account_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('optimize/geo', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
