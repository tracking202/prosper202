<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemDataengineCommand extends BaseCommand
{
    protected static $defaultName = 'system:dataengine';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show data engine job status and pending work');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('system/dataengine'), $input);
        return Command::SUCCESS;
    }
}
