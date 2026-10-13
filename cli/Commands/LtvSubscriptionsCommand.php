<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvSubscriptionsCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:subscriptions';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Account-wide subscription list joined to customers, filterable by lifecycle status')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter: ' . ServerLists::list(ServerLists::LTV_SUBSCRIPTION_STATUSES))
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (max 500)')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Pagination offset');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['status', 'limit', 'offset'] as $option) {
            $value = $input->getOption($option);
            if ($value !== null && $value !== '') {
                $params[$option] = (string) $value;
            }
        }

        $result = $this->client()->get('ltv/subscriptions', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
