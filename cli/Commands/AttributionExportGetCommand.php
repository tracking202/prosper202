<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportGetCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:get';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription("One attribution export: its status, rows, webhook answer and last error")
            ->addArgument('id', InputArgument::REQUIRED, 'Export ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AttributionExportCreateCommand::exportId($input->getArgument('id'));
        if ($id === null) {
            $output->writeln('<error>The export id must be a positive whole number; list them with attribution:export:list</error>');
            return Command::FAILURE;
        }
        $this->render($output, $this->client()->get('attribution/exports/' . $id), $input);
        return Command::SUCCESS;
    }
}
