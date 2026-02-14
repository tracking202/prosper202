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

class AttributionSnapshotListCommand extends Command
{
    protected static $defaultName = 'attribution:snapshot:list';

    protected function configure(): void
    {
        $this->setDescription('List snapshots for an attribution model')
            ->addArgument('model_id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('scope_type', null, InputOption::VALUE_REQUIRED, 'Filter: global, campaign, landing_page')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '100')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = [
            'limit' => $input->getOption('limit'),
            'offset' => $input->getOption('offset'),
        ];
        if ($input->getOption('scope_type')) {
            $params['scope_type'] = $input->getOption('scope_type');
        }

        $result = $client->get('attribution/models/' . $input->getArgument('model_id') . '/snapshots', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
