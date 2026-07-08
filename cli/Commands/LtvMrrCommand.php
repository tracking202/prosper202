<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvMrrCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:mrr';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Subscription economics — active MRR/ARR, status counts, monthly churn with its inputs');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('ltv/mrr', []);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
