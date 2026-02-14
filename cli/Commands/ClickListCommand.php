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

class ClickListCommand extends Command
{
    protected static $defaultName = 'click:list';

    protected function configure(): void
    {
        $this->setDescription('List clicks with optional filters')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Start timestamp (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'End timestamp (unix)')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter by PPC account')
            ->addOption('landing_page_id', null, InputOption::VALUE_REQUIRED, 'Filter by landing page')
            ->addOption('click_lead', null, InputOption::VALUE_REQUIRED, 'Filter by conversion: 0=clicks only, 1=conversions only')
            ->addOption('click_bot', null, InputOption::VALUE_REQUIRED, 'Filter by bot: 0=human, 1=bot')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = ApiClient::fromConfig(new Config());
        $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];

        foreach (['time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'landing_page_id', 'click_lead', 'click_bot'] as $p) {
            $val = $input->getOption($p);
            if ($val !== null) {
                $params[$p] = $val;
            }
        }

        $result = $client->get('clicks', $params);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
