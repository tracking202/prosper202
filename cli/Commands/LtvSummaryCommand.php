<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvSummaryCommand extends BaseCommand
{
    /** Query parameters shared by the LTV read commands, as the API names them (collectOptions() reads their options). */
    public const array LTV_PARAMS = ['period', 'time_from', 'time_to', 'by', 'sort', 'dir', 'limit', 'offset'];

    protected static $defaultName = 'ltv:summary';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Realized LTV totals — customers, revenue, avg LTV, AOV, repeat rate, MRR')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: ' . ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Acquisition window start (unix)')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'Acquisition window end (unix)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('ltv/summary', $this->collectOptions($input, self::LTV_PARAMS));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }

}
