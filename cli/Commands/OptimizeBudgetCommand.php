<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OptimizeBudgetCommand extends BaseCommand
{
    protected static $defaultName = 'optimize:budget';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Budget allocation recommendations across campaigns based on historical performance')
            ->addOption('total_budget', null, InputOption::VALUE_REQUIRED, 'Total budget to allocate (required)')
            ->addOption('target_metric', null, InputOption::VALUE_REQUIRED, 'Optimize for: roi, epc, conv_rate, total_net', 'roi')
            ->addOption('min_clicks', null, InputOption::VALUE_REQUIRED, 'Minimum clicks for inclusion', '50')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Days of history to analyze (7-90)', '30')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account ID')
            ->addOption('ppc_network_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC network ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $totalBudget = $input->getOption('total_budget');
        if ($totalBudget === null) {
            $output->writeln('<error>Missing required option: --total_budget</error>');
            return Command::FAILURE;
        }

        $params = ['total_budget' => $totalBudget];
        foreach (['target_metric', 'min_clicks', 'lookback_days', 'ppc_account_id', 'ppc_network_id'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get('optimize/budget', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
