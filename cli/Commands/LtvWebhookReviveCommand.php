<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * PUT /ltv/webhooks/{id} {"status": "active"}: a dead LTV webhook active
 * again, with what it missed in the last 7 days sent again unless
 * --no-requeue.
 */
class LtvWebhookReviveCommand extends BaseCommand
{
    protected static $defaultName = 'ltv:webhook:revive';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Make a dead LTV webhook active again, and send what it missed in the last 7 days')
            ->addArgument('id', InputArgument::REQUIRED, 'Webhook ID')
            ->addOption(
                'requeue',
                null,
                InputOption::VALUE_NEGATABLE,
                'Send what it did not deliver in the last 7 days (the default; --no-requeue: new events only, and'
                    . ' the deliveries still pending are held)'
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
        $requeue = $input->getOption('requeue') !== false;
        $this->render(
            $output,
            $this->client()->put('ltv/webhooks/' . $raw, ['status' => 'active', 'requeue' => $requeue]),
            $input
        );
        return Command::SUCCESS;
    }
}
