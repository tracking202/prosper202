<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use P202Cli\ApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigTestCommand extends Command
{
    protected static $defaultName = 'config:test';

    protected function configure(): void
    {
        $this->setDescription('Test connectivity to the remote Prosper202 instance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = new Config();
            $client = ApiClient::fromConfig($config);
            $result = $client->get('system/health');
            $output->writeln('<info>Connection successful!</info>');
            foreach ($result['data'] ?? $result as $k => $v) {
                $output->writeln("  $k: $v");
            }
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Connection failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
