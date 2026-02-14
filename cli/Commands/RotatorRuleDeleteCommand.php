<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RotatorRuleDeleteCommand extends Command
{
    protected static $defaultName = 'rotator:rule:delete';

    protected function configure(): void
    {
        $this->setDescription('Delete a rule from a rotator')
            ->addArgument('rotator_id', InputArgument::REQUIRED, 'Rotator ID')
            ->addArgument('rule_id', InputArgument::REQUIRED, 'Rule ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $result = $client->delete('rotators/' . $input->getArgument('rotator_id') . '/rules/' . $input->getArgument('rule_id'));
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
