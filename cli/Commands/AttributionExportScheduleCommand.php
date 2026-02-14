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

class AttributionExportScheduleCommand extends Command
{
    protected static $defaultName = 'attribution:export:schedule';

    protected function configure(): void
    {
        $this->setDescription('Schedule an attribution export')
            ->addArgument('model_id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('scope_type', null, InputOption::VALUE_REQUIRED, 'Scope: global, campaign, landing_page', 'global')
            ->addOption('scope_id', null, InputOption::VALUE_REQUIRED, 'Scope ID', '0')
            ->addOption('start_hour', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('end_hour', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: csv, json', 'csv')
            ->addOption('webhook_url', null, InputOption::VALUE_REQUIRED, 'Webhook URL for delivery')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [
            'scope_type' => $input->getOption('scope_type'),
            'scope_id' => (int)$input->getOption('scope_id'),
            'format' => $input->getOption('format'),
        ];

        foreach (['start_hour', 'end_hour', 'webhook_url'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }

        $client = ApiClient::fromConfig(new Config());
        $result = $client->post('attribution/models/' . $input->getArgument('model_id') . '/exports', $body);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
