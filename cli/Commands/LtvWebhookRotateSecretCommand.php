<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * POST /ltv/webhooks/{id}/rotate-secret: a new secret for an LTV webhook,
 * printed once, with the old one still signing for --overlap-hours.
 */
class LtvWebhookRotateSecretCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:webhook:rotate-secret';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Give an LTV webhook a new signing secret, shown once; the old one signs too for a while')
            ->addArgument('id', InputArgument::REQUIRED, 'Webhook ID')
            ->addOption(
                'overlap-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Hours the old secret still signs deliveries beside the new one, 0 to 168 (default 24; 0 stops it at once)'
            );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('id');
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/D', $raw) !== 1) {
            $output->writeln('<error>The webhook id must be a positive whole number; GET /api/v3/ltv/webhooks'
                . ' (p202 ltv webhooks list) lists them</error>');
            return Command::FAILURE;
        }
        $body = [];
        $overlap = $input->getOption('overlap-hours');
        if ($overlap !== null) {
            if (!is_string($overlap) || preg_match('/^(0|[1-9][0-9]{0,2})$/D', $overlap) !== 1 || (int) $overlap > 168) {
                $output->writeln('<error>--overlap-hours must be a whole number from 0 to 168</error>');
                return Command::FAILURE;
            }
            $body['overlap_hours'] = (int) $overlap;
        }
        $this->render($output, $this->client()->post('ltv/webhooks/' . $raw . '/rotate-secret', $body), $input);
        return Command::SUCCESS;
    }
}
