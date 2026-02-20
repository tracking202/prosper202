<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionSnapshotListCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:snapshot:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List snapshots for an attribution model')
            ->addArgument('model_id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('scope_type', null, InputOption::VALUE_REQUIRED, 'Filter: global, campaign, landing_page')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '100')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [
            'limit' => $input->getOption('limit'),
            'offset' => $input->getOption('offset'),
        ];
        if ($input->getOption('scope_type')) {
            $params['scope_type'] = $input->getOption('scope_type');
        }

        $result = $this->client()->get('attribution/models/' . $input->getArgument('model_id') . '/snapshots', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
