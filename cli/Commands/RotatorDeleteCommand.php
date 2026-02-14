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

class RotatorDeleteCommand extends Command
{
    protected static $defaultName = 'rotator:delete';

    protected function configure(): void
    {
        $this->setDescription('Delete a rotator and all its rules')
            ->addArgument('id', InputArgument::REQUIRED, 'Rotator ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $result = $client->delete('rotators/' . $input->getArgument('id'));
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
