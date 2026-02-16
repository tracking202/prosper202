<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigSetKeyCommand extends Command
{
    protected static $defaultName = 'config:set-key';

    protected function configure(): void
    {
        $this->setDescription('Set the API key for authentication')
            ->addArgument('key', InputArgument::REQUIRED, 'Your Prosper202 API key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $config->set('api_key', $input->getArgument('key'));
        $config->save();
        $output->writeln('<info>API key saved.</info>');
        return Command::SUCCESS;
    }
}
