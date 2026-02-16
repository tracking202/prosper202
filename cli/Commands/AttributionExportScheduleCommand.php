<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionExportScheduleCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:export:schedule';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Schedule an attribution export')
            ->addArgument('model_id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('scope_type', null, InputOption::VALUE_REQUIRED, 'Scope: global, campaign, landing_page', 'global')
            ->addOption('scope_id', null, InputOption::VALUE_REQUIRED, 'Scope ID', '0')
            ->addOption('start_hour', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('end_hour', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: csv, json', 'csv')
            ->addOption('webhook_url', null, InputOption::VALUE_REQUIRED, 'Webhook URL for delivery');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
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

        $result = $this->client()->post('attribution/models/' . $input->getArgument('model_id') . '/exports', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
