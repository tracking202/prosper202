<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvBreakdownCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:breakdown';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('LTV by acquisition source (campaign, ppc_account, landing_page) or by product')
            ->addOption('by', 'b', InputOption::VALUE_REQUIRED, 'Dimension: campaign, ppc_account, landing_page, product', 'campaign')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Window start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Window end (unix)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (max 500)')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Pagination offset');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('ltv/breakdown', LtvSummaryCommand::collectLtvParams($input));
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
