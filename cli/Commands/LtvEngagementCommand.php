<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LtvEngagementCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:engagement';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('One customer\'s engagement — campaigns/LPs browsed and instrumented events in the window, plus the suggested next offer')
            ->addArgument('id', InputArgument::REQUIRED, 'Customer ID')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Engagement window in days (default 90, max 365)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $customerId = (int) $input->getArgument('id');
        $params = [];
        $days = $input->getOption('days');
        if ($days !== null && $days !== '') {
            $params['days'] = (string) $days;
        }

        $engagement = $this->client()->get('ltv/customers/' . $customerId . '/engagement', $params);
        $nextOffer = $this->client()->get('ltv/customers/' . $customerId . '/next-offer', []);

        $combined = [
            'data' => [
                'engagement' => $engagement['data'] ?? $engagement,
                'next_offer' => $nextOffer['data'] ?? $nextOffer,
            ],
        ];
        $this->render($output, $combined, $input);
        return Command::SUCCESS;
    }
}
