<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConversionCreateCommand extends BaseCommand
{
    protected static $defaultName = 'conversion:create';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Manually log a conversion')
            ->addOption('click_id', null, InputOption::VALUE_REQUIRED, 'Click ID (required)')
            ->addOption('payout', null, InputOption::VALUE_REQUIRED, 'Payout amount (overrides campaign default)')
            ->addOption('transaction_id', null, InputOption::VALUE_REQUIRED, 'Transaction ID for dedup');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
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

        $result = $this->client()->post('conversions', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
