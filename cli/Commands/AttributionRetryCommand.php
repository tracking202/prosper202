<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionRetryCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:retry';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription(
            'Try the failed attribution work again now: failed conversions, visitor merges and model changes'
            . ' are made due and the worker run once; answers what was made due and the backlog after'
        );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->post('attribution/queue/retry'), $input);
        return Command::SUCCESS;
    }
}
