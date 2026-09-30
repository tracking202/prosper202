<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `click:conversions <id>`: every conversion on a click, whether it counts
 * toward the click's value, and why not — the PHP CLI's `p202 click
 * conversions`. --json prints the API's answer unchanged; the table shows
 * one line per conversion with the reason in one column, then the click's
 * value.
 */
class ClickConversionsCommand extends BaseCommand
{
    protected static $defaultName = 'click:conversions';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Explain a click\'s value: every conversion on it, whether it counts, and why not')
            ->addArgument('id', InputArgument::REQUIRED, 'Click ID (the internal id from click:list)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
            throw new \RuntimeException(sprintf('click id must be a positive integer, got "%s". Use the click_id column of click:list.', $id));
        }

        $result = $this->client()->get('clicks/' . $id . '/conversions');
        if ($this->isJson($input)) {
            $this->render($output, $result, $input);
            return Command::SUCCESS;
        }

        $this->render($output, ['data' => self::tableRows($result['data'] ?? [])], $input);
        foreach (self::summary($result['click'] ?? []) as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }

    /**
     * One flat line per conversion, as the Go CLI's table draws it.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function tableRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $counts = 'counted';
            if (empty($r['counted'])) {
                $counts = (string) ($r['not_counted_reason'] ?? '');
                if (!empty($r['superseded_reason'])) {
                    $counts .= ' (' . $r['superseded_reason'] . ')';
                }
            }
            $out[] = [
                'conv_id' => $r['conv_id'] ?? '',
                'amount' => $r['amount'] ?? '',
                'counts' => $counts,
                'source' => $r['source'] ?? '',
                'linked_to' => is_array($r['linked_to'] ?? null) ? (string) ($r['linked_to']['label'] ?? '') : '',
                'transaction_id' => $r['transaction_id'] ?? '',
                'event_name' => $r['event_name'] ?? '',
                'conv_time' => $r['conv_time'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $click
     * @return list<string>
     */
    public static function summary(array $click): array
    {
        if ($click === []) {
            return [];
        }
        $value = !empty($click['lead']) ? (string) ($click['click_payout'] ?? '') : 'not converted';
        $lines = ['', sprintf(
            'Click %s: %s (%s mode), %s of %s conversions counted.',
            (string) ($click['click_id'] ?? '?'),
            $value,
            (string) ($click['payout_mode'] ?? '?'),
            (string) ($click['counted_rows'] ?? '?'),
            (string) ($click['rows'] ?? '?')
        )];
        if (($click['ledger_state'] ?? '') === 'pre_ledger') {
            $lines[] = 'It converted before the conversion ledger: its value is the click\'s own figure until its next conversion carries it in as a row.';
        } elseif (array_key_exists('matches_click', $click) && $click['matches_click'] === false) {
            $lines[] = sprintf(
                '<comment>Warning: the counted conversions add up to %s, which is not the click\'s %s. The next conversion on this click recomputes it.</comment>',
                (string) ($click['ledger_value'] ?? 'no value'),
                $value
            );
        }

        return $lines;
    }
}
