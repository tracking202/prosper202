<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionQueueCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:queue';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription("The attribution worker's backlog: conversions waiting, failing (with the error), and why each was queued");
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('attribution/queue');
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
