<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportListCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List exports for an attribution model')
            ->addArgument('model_id', InputArgument::REQUIRED, 'Model ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client()->get('attribution/models/' . $input->getArgument('model_id') . '/exports');
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
