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

class RotatorListCommand extends Command
{
    protected static $defaultName = 'rotator:list';

    protected function configure(): void
    {
        $this->setDescription('List rotators')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $result = $client->get('rotators', [
            'limit' => $input->getOption('limit'),
            'offset' => $input->getOption('offset'),
        ]);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
