<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\OptionName;
use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `conversion:match-methods`: each campaign's conversions by how their click
 * was found (GET /conversions/match-methods) — the PHP CLI's `p202
 * conversion match-methods`. --json prints the API's answer unchanged; the
 * table is one line per campaign and method, then every method's totals.
 */
class ConversionMatchMethodsCommand extends BaseCommand
{
    protected static $defaultName = 'conversion:match-methods';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Conversions by campaign and by how their click was found: ' . ServerLists::list(ServerLists::conversionMatchMethods()))
            ->addOption('campaign-id', null, InputOption::VALUE_REQUIRED, 'Only this campaign')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Range of conversion times: ' . ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Start: unix seconds or a date')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'End: unix seconds or a date');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        $campaign = $input->getOption('campaign-id');
        if ($campaign !== null) {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', (string) $campaign) !== 1) {
                throw new \RuntimeException(sprintf('--campaign-id must be a positive integer (a campaign id from campaign:list), got "%s"', (string) $campaign));
            }
            $params['campaign_id'] = $campaign;
        }
        $period = $input->getOption('period');
        if ($period !== null) {
            if (!in_array($period, ServerLists::periods(), true)) {
                throw new \RuntimeException(sprintf('--period must be one of %s, got "%s"', ServerLists::list(ServerLists::periods()), (string) $period));
            }
            $params['period'] = $period;
        }
        foreach (['time_from', 'time_to'] as $p) {
            $val = $input->getOption(OptionName::of($p));
            if ($val !== null) {
                $params[$p] = $val;
            }
        }

        $result = $this->client()->get('conversions/match-methods', $params);
        if ($this->isJson($input)) {
            $this->render($output, $result, $input);
            return Command::SUCCESS;
        }
        $this->render($output, ['data' => self::rows($result['data'] ?? [])], $input);
        if (($result['data'] ?? []) !== []) {
            $output->writeln(['', 'Totals by match method:']);
            $this->render($output, ['data' => self::rows($result['totals'] ?? [])], $input);
        }

        return Command::SUCCESS;
    }

    /**
     * One flat line per group, as the Go CLI's table draws it.
     *
     * @param array<int, array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    public static function rows(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $row = [];
            if (array_key_exists('campaign_id', $g)) {
                $row['campaign_id'] = $g['campaign_id'];
                $row['campaign_name'] = $g['campaign_name'] ?? '';
            }
            $out[] = $row + [
                'match_method' => $g['match_method'] ?? '',
                'conversions' => $g['conversions'] ?? '',
                'counted' => $g['counted'] ?? '',
                'income' => $g['income'] ?? '',
            ];
        }

        return $out;
    }
}
