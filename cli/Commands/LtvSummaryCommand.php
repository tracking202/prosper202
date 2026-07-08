<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvSummaryCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:summary';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Realized LTV totals — customers, revenue, avg LTV, AOV, repeat rate, MRR')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Acquisition window start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Acquisition window end (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('ltv/summary', self::collectLtvParams($input));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }

    public static function collectLtvParams(InputInterface $input): array
    {
        $params = [];
        foreach (['period', 'time_from', 'time_to', 'by', 'sort', 'dir', 'limit', 'offset'] as $p) {
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
