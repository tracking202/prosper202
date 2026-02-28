<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class BulkUpdateBidsCommand extends BaseCommand
{
    protected static $defaultName = 'bulk:update-bids';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Bulk update CPC bids across multiple trackers')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Filter trackers by campaign ID')
            ->addOption('ppc_account_id', null, InputOption::VALUE_REQUIRED, 'Filter trackers by PPC account ID')
            ->addOption('set_cpc', null, InputOption::VALUE_REQUIRED, 'Set absolute CPC value')
            ->addOption('adjust_pct', null, InputOption::VALUE_REQUIRED, 'Adjust CPC by percentage (e.g., +10, -20)')
            ->addOption('max_cpc', null, InputOption::VALUE_REQUIRED, 'Cap CPC at this maximum value')
            ->addOption('min_cpc', null, InputOption::VALUE_REQUIRED, 'Floor CPC at this minimum value')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $setCpc = $input->getOption('set_cpc');
        $adjustPct = $input->getOption('adjust_pct');

        if ($setCpc === null && $adjustPct === null) {
            $output->writeln('<error>Provide either --set_cpc or --adjust_pct</error>');
            return Command::FAILURE;
        }
        if ($setCpc !== null && $adjustPct !== null) {
            $output->writeln('<error>Use either --set_cpc or --adjust_pct, not both</error>');
            return Command::FAILURE;
        }

        // Fetch trackers matching filters
        $listParams = ['limit' => '500', 'offset' => '0'];
        foreach (['aff_campaign_id' => 'filter[aff_campaign_id]', 'ppc_account_id' => 'filter[ppc_account_id]'] as $opt => $param) {
            $val = $input->getOption($opt);
            if ($val !== null) {
                $listParams[$param] = $val;
            }
        }

        $result = $this->client()->get('trackers', $listParams);
        $trackers = $result['data'] ?? [];

        if (empty($trackers)) {
            $output->writeln('<comment>No trackers match the given filters.</comment>');
            return Command::SUCCESS;
        }

        // Calculate new bids
        $maxCpc = $input->getOption('max_cpc') !== null ? (float)$input->getOption('max_cpc') : null;
        $minCpc = $input->getOption('min_cpc') !== null ? (float)$input->getOption('min_cpc') : null;
        $updates = [];

        foreach ($trackers as $t) {
            $currentCpc = (float)($t['click_cpc'] ?? 0);
            $trackerId = (int)($t['tracker_id'] ?? $t['id'] ?? 0);

            if ($setCpc !== null) {
                $newCpc = (float)$setCpc;
            } else {
                $pct = (float)$adjustPct;
                $newCpc = $currentCpc * (1 + $pct / 100);
            }

            if ($maxCpc !== null && $newCpc > $maxCpc) {
                $newCpc = $maxCpc;
            }
            if ($minCpc !== null && $newCpc < $minCpc) {
                $newCpc = $minCpc;
            }
            $newCpc = max(0, round($newCpc, 4));

            $updates[] = [
                'tracker_id'  => $trackerId,
                'current_cpc' => $currentCpc,
                'new_cpc'     => $newCpc,
            ];
        }

        if ($this->isJson($input)) {
            // In JSON mode, preview the plan
            if (!$input->getOption('force')) {
                $output->writeln(json_encode([
                    'preview' => true,
                    'updates' => $updates,
                    'total_trackers' => count($updates),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }
        } else {
            $output->writeln(sprintf('<info>Found %d trackers to update:</info>', count($updates)));
            foreach (array_slice($updates, 0, 10) as $u) {
                $output->writeln(sprintf(
                    '  Tracker #%d: $%.4f -> $%.4f',
                    $u['tracker_id'],
                    $u['current_cpc'],
                    $u['new_cpc']
                ));
            }
            if (count($updates) > 10) {
                $output->writeln(sprintf('  ... and %d more', count($updates) - 10));
            }
        }

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Apply bid changes to %d trackers? [y/N] ', count($updates)),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Cancelled.</comment>');
                return Command::SUCCESS;
            }
        }

        // Apply updates
        $success = 0;
        $failed = 0;
        foreach ($updates as $u) {
            try {
                $this->client()->put('trackers/' . $u['tracker_id'], ['click_cpc' => (string)$u['new_cpc']]);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                if (!$this->isJson($input)) {
                    $output->writeln(sprintf('<error>Failed tracker #%d: %s</error>', $u['tracker_id'], $e->getMessage()));
                }
            }
        }

        if ($this->isJson($input)) {
            $output->writeln(json_encode([
                'applied' => true,
                'success' => $success,
                'failed'  => $failed,
                'updates' => $updates,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('<info>Updated %d trackers successfully.</info>', $success));
            if ($failed > 0) {
                $output->writeln(sprintf('<error>%d trackers failed to update.</error>', $failed));
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
