<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvCustomersCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:customers';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List customers with LTV rollups, or show one customer in full (CRM, aliases, custom fields, recent revenue)')
            ->addArgument('id', InputArgument::OPTIONAL, 'Customer ID for a detail view')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Period: today, yesterday, last7, last30, last90')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Acquisition window start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Acquisition window end (unix)')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort: total_revenue, order_count, last_activity_time, first_seen_time, mrr')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Sort direction: ASC or DESC')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (max 500)')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Pagination offset');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');
        if ($id !== null) {
            $result = $this->client()->get('ltv/customers/' . (int) $id, []);
        } else {
            $result = $this->client()->get('ltv/customers', LtvSummaryCommand::collectLtvParams($input));
        }
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
