<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemDbStatsCommand extends BaseCommand
{
    protected static $defaultName = 'system:db-stats';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show database table row counts and size');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('system/db-stats'), $input);
        return Command::SUCCESS;
    }
}
