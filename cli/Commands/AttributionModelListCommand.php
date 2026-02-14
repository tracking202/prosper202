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

class AttributionModelListCommand extends Command
{
    protected static $defaultName = 'attribution:model:list';

    protected function configure(): void
    {
        $this->setDescription('List attribution models')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by type: first_touch, last_touch, linear, time_decay, position_based, algorithmic')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = [];
        if ($input->getOption('type')) {
            $params['type'] = $input->getOption('type');
        }
        $result = $client->get('attribution/models', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
