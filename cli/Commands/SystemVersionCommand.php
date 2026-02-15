<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemVersionCommand extends BaseCommand
{
    protected static $defaultName = 'system:version';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show Prosper202 and system version info');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('system/version'), $input);
        return Command::SUCCESS;
    }
}
