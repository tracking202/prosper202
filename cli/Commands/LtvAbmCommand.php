<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvAbmCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:abm';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('ABM account view — companies ranked by engagement with scores, depth metrics, revenue and MRR; --company drills into one account\'s contacts')
            ->addOption('company', 'c', InputOption::VALUE_REQUIRED, 'Drill into one company by name')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Engagement window in days (default 90, max 365)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (max 500)')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Pagination offset');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['days', 'limit', 'offset'] as $option) {
            $value = $input->getOption($option);
            if ($value !== null && $value !== '') {
                $params[$option] = (string) $value;
            }
        }

        $company = $input->getOption('company');
        if ($company !== null && trim((string) $company) !== '') {
            $params['name'] = trim((string) $company);
            $result = $this->client()->get('ltv/abm/company', $params);
        } else {
            $result = $this->client()->get('ltv/abm', $params);
        }

        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
