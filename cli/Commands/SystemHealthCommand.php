<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemHealthCommand extends BaseCommand
{
    protected static $defaultName = 'system:health';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Check system health');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('system/health'), $input);
        return Command::SUCCESS;
    }
}
