<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * GET /apps/report: the cross-platform app report — Apple's postbacks and
 * Android's installs, with the goals they reached. The Go CLI's
 * `p202 app report`. The server refuses a grouping or a filter only one
 * platform has unless that platform is asked for; so does this, first,
 * naming the option.
 */
class AppReportCommand extends BaseCommand
{
    protected static $defaultName = 'app:report';

    private const SHARED_GROUPINGS = ['day', 'registration', 'platform'];
    private const IOS_GROUPINGS = ['ad-network', 'source', 'country', 'version', 'protocol', 'conversion-type'];
    private const ANDROID_GROUPINGS = ['campaign', 'match-state', 'integrity-state', 'goal'];
    private const SHARED_FILTERS = ['time_from', 'time_to', 'registration_id', 'registration_ids'];
    private const IOS_FILTERS = ['signature', 'protocol', 'conversion_type', 'ad_network_id', 'country_code', 'source_identifier', 'postback_version', 'did_win'];

    /**
     * Options whose API parameter has another name: `--version` is the
     * application's own option (print the CLI's version), so the postback
     * version filter cannot take it.
     */
    private const PARAM_FOR_OPTION = ['postback_version' => 'version'];
    private const ANDROID_FILTERS = ['match_state', 'integrity_state', 'trusted', 'test', 'aff_campaign_id'];

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Cross-platform app report: iOS postbacks and Android installs, with the goals they reached')
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'ios, android, or both (default: both)')
            ->addOption('group_by', null, InputOption::VALUE_REQUIRED, 'day, registration, platform; with --platform=ios also ' . implode(', ', self::IOS_GROUPINGS) . '; with --platform=android also ' . implode(', ', self::ANDROID_GROUPINGS), 'day')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max groups per platform (default 100)')
            ->addOption('time_from', null, InputOption::VALUE_REQUIRED, 'Range start (unix)')
            ->addOption('time_to', null, InputOption::VALUE_REQUIRED, 'Range end (unix)')
            ->addOption('registration_id', null, InputOption::VALUE_REQUIRED, 'Only this registration')
            ->addOption('registration_ids', null, InputOption::VALUE_REQUIRED, 'Only these registrations, comma-separated')
            ->addOption('signature', null, InputOption::VALUE_REQUIRED, 'iOS: valid, invalid, unverifiable, development')
            ->addOption('protocol', null, InputOption::VALUE_REQUIRED, 'iOS: skadnetwork (skan) or adattributionkit (aak)')
            ->addOption('conversion_type', null, InputOption::VALUE_REQUIRED, 'iOS: download, redownload, re-engagement')
            ->addOption('ad_network_id', null, InputOption::VALUE_REQUIRED, 'iOS: this ad network')
            ->addOption('country_code', null, InputOption::VALUE_REQUIRED, 'iOS: this country')
            ->addOption('source_identifier', null, InputOption::VALUE_REQUIRED, 'iOS: this SKAN 4 source identifier')
            ->addOption('postback_version', null, InputOption::VALUE_REQUIRED, 'iOS: this postback version (the API\'s version filter)')
            ->addOption('did_win', null, InputOption::VALUE_REQUIRED, 'iOS: 1 winning, 0 losing')
            ->addOption('match_state', null, InputOption::VALUE_REQUIRED, 'Android: this match state')
            ->addOption('integrity_state', null, InputOption::VALUE_REQUIRED, 'Android: this Play Integrity state')
            ->addOption('trusted', null, InputOption::VALUE_REQUIRED, 'Android: trusted, refuted or unvouched (goals are then counted over it)')
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Android: 1 test installs only, 0 real ones only')
            ->addOption('aff_campaign_id', null, InputOption::VALUE_REQUIRED, 'Android: installs on this campaign\'s clicks');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->render($output, $this->client()->get('apps/report', self::params($input)), $input);

        return Command::SUCCESS;
    }

    /**
     * The query the options make, refused before any request when an option
     * names one platform and --platform does not.
     *
     * @return array<string, mixed>
     */
    public static function params(InputInterface $input): array
    {
        $platform = strtolower(trim((string) ($input->getOption('platform') ?? '')));
        if ($platform === 'all') {
            $platform = '';
        }
        if ($platform !== '' && $platform !== 'ios' && $platform !== 'android') {
            throw new \RuntimeException('--platform must be ios, android or all (leave it out for both), got "' . $platform . '".');
        }
        $groupBy = (string) $input->getOption('group_by');
        $groupings = [...self::SHARED_GROUPINGS, ...self::IOS_GROUPINGS, ...self::ANDROID_GROUPINGS];
        if (!in_array($groupBy, $groupings, true)) {
            throw new \RuntimeException('--group_by must be one of: ' . implode(', ', $groupings) . '; got "' . $groupBy . '".');
        }
        if (in_array($groupBy, self::IOS_GROUPINGS, true) && $platform !== 'ios') {
            throw new \RuntimeException('--group_by=' . $groupBy . ' is a dimension of Apple\'s postbacks only: add --platform=ios, or group by '
                . implode(', ', self::SHARED_GROUPINGS) . ' for both platforms.');
        }
        if (in_array($groupBy, self::ANDROID_GROUPINGS, true) && $platform !== 'android') {
            throw new \RuntimeException('--group_by=' . $groupBy . ' is a dimension of Android installs only: add --platform=android, or group by '
                . implode(', ', self::SHARED_GROUPINGS) . ' for both platforms.');
        }

        $params = ['group_by' => $groupBy];
        if ($platform !== '') {
            $params['platform'] = $platform;
        }
        if ($input->getOption('limit') !== null) {
            $params['limit'] = $input->getOption('limit');
        }
        foreach ([[self::SHARED_FILTERS, null], [self::IOS_FILTERS, 'ios'], [self::ANDROID_FILTERS, 'android']] as [$filters, $only]) {
            foreach ($filters as $filter) {
                $value = $input->getOption($filter);
                if ($value === null || $value === '') {
                    continue;
                }
                if ($only !== null && $platform !== $only) {
                    throw new \RuntimeException('--' . $filter . ' filters ' . ($only === 'ios' ? 'Apple\'s postbacks' : 'Android installs')
                        . ' only: add --platform=' . $only . '.');
                }
                $params[self::PARAM_FOR_OPTION[$filter] ?? $filter] = $value;
            }
        }

        return $params;
    }
}
