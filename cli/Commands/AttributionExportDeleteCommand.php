<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AttributionExportDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:delete';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete an attribution export and its file (not while it is running)')
            ->addArgument('id', InputArgument::REQUIRED, 'Export ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = AttributionExportCreateCommand::exportId($input->getArgument('id'));
        if ($id === null) {
            $output->writeln('<error>The export id must be a positive whole number; list them with attribution:export:list</error>');
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(sprintf('Delete attribution export %d and its file? [y/N] ', $id), false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->client()->delete('attribution/exports/' . $id);
        $output->writeln(sprintf('<info>Deleted attribution export #%d and its file.</info>', $id));
        return Command::SUCCESS;
    }
}
