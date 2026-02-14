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

class ConversionListCommand extends Command
{
    protected static $defaultName = 'conversion:list';

    protected function configure(): void
    {
        $this->setDescription('List conversions')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];

        foreach (['campaign_id', 'time_from', 'time_to'] as $p) {
            $val = $input->getOption($p);
            if ($val !== null) {
                $params[$p] = $val;
            }
        }

        $result = $client->get('conversions', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
