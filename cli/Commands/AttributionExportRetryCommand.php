<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportRetryCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:retry';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Queue a failed attribution export again, with its attempts reset')
            ->addArgument('id', InputArgument::REQUIRED, 'Export ID');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AttributionExportCreateCommand::exportId($input->getArgument('id'));
        if ($id === null) {
            $output->writeln('<error>The export id must be a positive whole number; list them with attribution:export:list</error>');
            return Command::FAILURE;
        }
        $this->render($output, $this->client()->post('attribution/exports/' . $id . '/retry'), $input);
        return Command::SUCCESS;
    }
}
