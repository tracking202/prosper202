<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ConversionDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'conversion:delete';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete a conversion')
            ->addArgument('id', InputArgument::REQUIRED, 'Conversion ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to delete conversion %s? [y/N] ', $id),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $result = $this->client()->delete('conversions/' . $id);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
