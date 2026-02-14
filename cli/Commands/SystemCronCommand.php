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

class SystemCronCommand extends Command
{
    protected static $defaultName = 'system:cron';
    protected function configure(): void
    {
        $this->setDescription('Show cron job status and recent logs')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        Formatter::output($output, $client->get('system/cron'), (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
