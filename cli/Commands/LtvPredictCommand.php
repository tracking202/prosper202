<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvPredictCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:predict';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Predictive LTV — deterministic projection with guards; every number ships with its inputs')
            ->addOption('by', 'b', InputOption::VALUE_REQUIRED, 'Also project per cohort: campaign, ppc_account, landing_page')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Acquisition window start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Acquisition window end (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('ltv/predict', LtvSummaryCommand::collectLtvParams($input));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
