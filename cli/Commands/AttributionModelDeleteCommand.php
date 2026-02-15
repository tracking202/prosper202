<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class AttributionModelDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:delete';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete an attribution model and all related data')
            ->addArgument('id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to delete attribution model %s? [y/N] ', $id),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $result = $this->client()->delete('attribution/models/' . $id);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
