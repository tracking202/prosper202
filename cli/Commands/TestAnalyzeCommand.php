<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestAnalyzeCommand extends BaseCommand
{
    protected static $defaultName = 'test:analyze';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('A/B test statistical significance analysis for rotator split tests')
            ->addArgument('rotator_id', InputArgument::REQUIRED, 'Rotator ID to analyze')
            ->addOption('metric', 'm', InputOption::VALUE_REQUIRED, 'Metric for significance test: conv_rate, epc, roi', 'conv_rate')
            ->addOption('confidence_level', null, InputOption::VALUE_REQUIRED, 'Required confidence level (0.80-0.99)', '0.95')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $rotatorId = $input->getArgument('rotator_id');

        $params = [];
        foreach (['metric', 'confidence_level', 'period', 'time_from', 'time_to'] as $opt) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $params[$opt] = $val;
            }
        }

        $result = $this->client()->get("test/analyze/$rotatorId", $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
