<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigShowCommand extends Command
{
    protected static $defaultName = 'config:show';

    protected function configure(): void
    {
        $this->setDescription('Show current configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $data = $config->all();

        // Mask API key
        if (isset($data['api_key']) && strlen($data['api_key']) > 8) {
            $data['api_key'] = substr($data['api_key'], 0, 4) . '...' . substr($data['api_key'], -4);
        }

        $output->writeln("<info>Config file:</info> " . $config->configPath());
        foreach ($data as $k => $v) {
            $output->writeln("<info>$k:</info> $v");
        }

        if (empty($data)) {
            $output->writeln('<comment>No configuration set. Run config:set-url and config:set-key first.</comment>');
        }

        return Command::SUCCESS;
    }
}
