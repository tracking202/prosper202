<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigSetUrlCommand extends Command
{
    protected static $defaultName = 'config:set-url';

    protected function configure(): void
    {
        $this->setDescription('Set the remote Prosper202 URL')
            ->addArgument('url', InputArgument::REQUIRED, 'The base URL of your Prosper202 installation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $url = rtrim((string) $input->getArgument('url'), '/');
        $config->set('url', $url);
        $config->save();
        $output->writeln("<info>URL set to:</info> $url");
        return Command::SUCCESS;
    }
}
