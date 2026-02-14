<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemErrorsCommand extends Command
{
    protected static $defaultName = 'system:errors';
    protected function configure(): void
    {
        $this->setDescription('Show recent MySQL errors')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->get('system/errors', ['limit' => $input->getOption('limit')]), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
