<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Prosper202\Attribution\ExportStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportListCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List attribution exports, newest first')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter: ' . implode(', ', ExportStore::STATUSES))
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows, 1-200 (default 50)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['status', 'limit'] as $opt) {
            $v = $input->getOption($opt);
            if ($v !== null && $v !== '') {
                $params[$opt] = (string) $v;
            }
        }
        $this->render($output, $this->client()->get('attribution/exports', $params), $input);
        return Command::SUCCESS;
    }
}
