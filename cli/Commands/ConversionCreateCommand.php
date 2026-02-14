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

class ConversionCreateCommand extends Command
{
    protected static $defaultName = 'conversion:create';

    protected function configure(): void
    {
        $this->setDescription('Manually log a conversion')
            ->addOption('click_id', null, InputOption::VALUE_REQUIRED, 'Click ID (required)')
            ->addOption('payout', null, InputOption::VALUE_REQUIRED, 'Payout amount (overrides campaign default)')
            ->addOption('transaction_id', null, InputOption::VALUE_REQUIRED, 'Transaction ID for dedup')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clickId = $input->getOption('click_id');
        if (!$clickId) {
            $output->writeln('<error>--click_id is required</error>');
            return Command::FAILURE;
        }

        $body = ['click_id' => (int)$clickId];
        if ($input->getOption('payout') !== null) {
            $body['payout'] = (float)$input->getOption('payout');
        }
        if ($input->getOption('transaction_id') !== null) {
            $body['transaction_id'] = $input->getOption('transaction_id');
        }

        $client = ApiClient::fromConfig(new Config());
        $result = $client->post('conversions', $body);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
