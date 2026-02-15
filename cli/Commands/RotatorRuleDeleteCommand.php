<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RotatorRuleDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'rotator:rule:delete';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Delete a rule from a rotator')
            ->addArgument('rotator_id', InputArgument::REQUIRED, 'Rotator ID')
            ->addArgument('rule_id', InputArgument::REQUIRED, 'Rule ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $rotatorId = $input->getArgument('rotator_id');
        $ruleId = $input->getArgument('rule_id');

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Are you sure you want to delete rule %s from rotator %s? [y/N] ', $ruleId, $rotatorId),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $result = $this->client()->delete('rotators/' . $rotatorId . '/rules/' . $ruleId);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
