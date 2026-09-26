<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportDownloadCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:download';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription("Download an attribution export's CSV to --output, or to stdout")
            ->addArgument('id', InputArgument::REQUIRED, 'Export ID')
            ->addOption('output', 'O', InputOption::VALUE_REQUIRED, 'Write the CSV to this file');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AttributionExportCreateCommand::exportId($input->getArgument('id'));
        if ($id === null) {
            $output->writeln('<error>The export id must be a positive whole number; list them with attribution:export:list</error>');
            return Command::FAILURE;
        }
        $body = $this->client()->download('attribution/exports/' . $id . '/download');
        $path = $input->getOption('output');
        if (!is_string($path) || $path === '') {
            $output->write($body, false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }
        if (file_put_contents($path, $body) !== strlen($body)) {
            $output->writeln(sprintf('<error>Could not write %s</error>', $path));
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Export %d written to %s (%d bytes).</info>', $id, $path, strlen($body)));
        return Command::SUCCESS;
    }
}
