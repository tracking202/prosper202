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

class ConversionGetCommand extends Command
{
    protected static $defaultName = 'conversion:get';

    protected function configure(): void
    {
        $this->setDescription('Get conversion details')
            ->addArgument('id', InputArgument::REQUIRED, 'Conversion ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $result = $client->get('conversions/' . $input->getArgument('id'));
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
