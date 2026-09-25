<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Prosper202\Attribution\AttributionReports;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionBreakdownCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:breakdown';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Attributed conversions, revenue, cost and ROI grouped by a click dimension')
            ->addOption('group_by', 'g', InputOption::VALUE_REQUIRED, 'Dimension: ' . implode(', ', AttributionReports::dimensions()), 'campaign')
            ->addOption('model_id', 'm', InputOption::VALUE_REQUIRED, 'Model (default: each campaign\'s override, else the account default)')
            ->addOption('compare_model_id', null, InputOption::VALUE_REQUIRED, 'A second model, side by side')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Unix start time')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Unix end time')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows, 1-1000 (default 100)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['group_by', 'model_id', 'compare_model_id', 'period', 'time_from', 'time_to', 'limit'] as $opt) {
            $v = $input->getOption($opt);
            if ($v !== null && $v !== '') {
                $params[$opt] = (string) $v;
            }
        }
        $result = $this->client()->get('attribution/reports/breakdown', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
